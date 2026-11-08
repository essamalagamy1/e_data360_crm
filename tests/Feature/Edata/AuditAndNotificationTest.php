<?php

namespace Tests\Feature\Edata;

use App\Models\EdataAuditLog;
use App\Models\Notification;
use App\Models\ProjectTask;
use App\Support\Edata\Audit;
use App\Support\Edata\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class AuditAndNotificationTest extends EdataTestCase
{
    public function test_project_status_change_is_logged_with_old_and_new_values(): void
    {
        $project = $this->project(['status' => 'new']);

        $this->actingAs($this->owner)->post(route('edata.projects.status', $project->id), ['status' => 'in_progress']);

        $log = EdataAuditLog::where('entity_type', 'project')->where('entity_id', $project->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame('status_changed', $log->action);
        $this->assertSame('new', $log->old_values['status']);
        $this->assertSame('in_progress', $log->new_values['status']);
        $this->assertSame($this->owner->name, $log->user_name);
    }

    public function test_password_change_is_logged_without_any_password_value(): void
    {
        $person = $this->staff();

        $this->actingAs($this->owner)->post(route('edata.people.password', $person->id), ['password' => 'BrandNewPass9', 'password_confirmation' => 'BrandNewPass9'])->assertSessionHas('success');

        $log = EdataAuditLog::where('entity_type', 'user')->where('entity_id', $person->id)->orderByDesc('id')->firstOrFail();
        $raw = json_encode([$log->old_values, $log->new_values]);

        $this->assertStringContainsString('password_changed', $raw);
        $this->assertStringNotContainsString('BrandNewPass9', $raw);
        $this->assertStringNotContainsString('$2y$', $raw);
    }

    public function test_audit_rows_cannot_be_changed_or_deleted(): void
    {
        $this->actingAs($this->owner);
        Audit::record('created', 'settings', null, 'protected row');
        $log = EdataAuditLog::orderByDesc('id')->firstOrFail();

        $log->entity_label = 'tampered';
        $log->save();
        $this->assertSame('protected row', EdataAuditLog::find($log->id)->entity_label);

        $log->delete();
        $this->assertNotNull(EdataAuditLog::find($log->id));
    }

    public function test_no_route_writes_to_the_audit_log(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'activity-log')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
            }
        }
    }

    public function test_audit_log_of_another_company_is_not_listed(): void
    {
        EdataAuditLog::create(['company_id' => 999999, 'action' => 'created', 'entity_type' => 'project', 'entity_label' => 'secret-of-other-company', 'user_name' => 'x']);

        $this->actingAs($this->owner)->get(route('edata.audit.index'))->assertOk()->assertDontSee('secret-of-other-company');
    }

    public function test_assigning_a_task_notifies_the_assignee_and_links_to_it(): void
    {
        $assignee = $this->staff();
        $project = $this->project();

        $this->actingAs($this->owner)->post(route('edata.tasks.store'), [
            'name' => 'Build the report',
            'project_id' => $project->id,
            'priority' => 'high',
            'assign_to' => [$assignee->id],
        ])->assertSessionHasNoErrors();

        $notification = Notification::where('user_id', $assignee->id)->where('type', 'task_assigned')->firstOrFail();
        $data = json_decode($notification->data, true);
        $this->assertSame('Build the report', $data['body']);
        $this->assertSame(0, (int) $notification->is_read);

        $task = ProjectTask::where('name', 'Build the report')->firstOrFail();
        $this->actingAs($assignee)->get(route('edata.notifications.open', $notification->id))->assertRedirect(route('edata.tasks.show', $task->id, false));
        $this->assertSame(1, (int) $notification->fresh()->is_read);
    }

    public function test_assignee_gains_access_to_the_project_of_their_task(): void
    {
        $assignee = $this->staff(['manage project', 'view project']);
        $project = $this->project();

        $this->actingAs($this->owner)->post(route('edata.tasks.store'), [
            'name' => 'T', 'project_id' => $project->id, 'priority' => 'low', 'assign_to' => [$assignee->id],
        ]);

        \App\Support\Edata\Access::flushCache();
        $this->actingAs($assignee)->get(route('projects.show', $project->id))->assertOk();
    }

    public function test_a_user_cannot_open_another_users_notification(): void
    {
        $owner = $this->staff();
        $other = $this->staff();
        Notifier::send($owner->id, 'x', 'Private', null, '/my', null, true);
        $row = Notification::where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($other)->get(route('edata.notifications.open', $row->id))->assertNotFound();
    }

    public function test_notification_links_cannot_leave_the_application(): void
    {
        $user = $this->staff();
        Notifier::send($user->id, 'x', 'Evil', null, 'https://evil.example/phish', null, true);
        $row = Notification::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)->get(route('edata.notifications.open', $row->id))->assertRedirect(route('edata.notifications.index'));
    }

    public function test_due_soon_and_overdue_alerts_are_sent_once(): void
    {
        $user = $this->staff();
        $project = $this->project();
        $stage = (int) DB::table('task_stages')->where('created_by', $this->company)->orderBy('order')->value('id');
        $task = new ProjectTask();
        $task->forceFill(['name' => 'Late one', 'project_id' => $project->id, 'assign_to' => (string) $user->id, 'priority' => 'low', 'stage_id' => $stage, 'end_date' => now()->subDay()->toDateString(), 'created_by' => $this->company]);
        $task->save();

        Notifier::runChecks($this->company);
        Notifier::runChecks($this->company);

        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'task_overdue')->count());
    }
}
