<?php

namespace Tests\Feature\Edata;

use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opens every rebuilt screen as each kind of user and fails on any server error,
 * so a broken view cannot reach production unnoticed.
 */
class ScreensTest extends EdataTestCase
{
    private function urls(): array
    {
        $employeeUser = $this->staff(['manage project'], 'own', 'Screen Employee');
        $this->employeeFor($employeeUser);
        $customer = $this->customer();
        $project = $this->project(['customer_id' => $customer->id, 'manager_id' => $employeeUser->id, 'start_date' => now()->subDays(3)->toDateString(), 'end_date' => now()->addDays(3)->toDateString()], [$employeeUser]);
        $task = ProjectTask::create(['name' => 'Screen task', 'project_id' => $project->id, 'assign_to' => (string) $employeeUser->id, 'priority' => 'high', 'end_date' => now()->addDay()->toDateString(), 'stage_id' => (int) DB::table('task_stages')->where('created_by', $this->company)->value('id'), 'created_by' => $this->company]);
        $invoice = $this->invoice(1000, 1, ['customer_id' => $customer->id, 'project_id' => $project->id]);
        $product = $this->product();
        $role = $employeeUser->roles->first();

        return [
            route('dashboard'),
            route('dashboard', ['range' => '7']),
            route('dashboard', ['range' => 'year', 'project' => $project->id]),
            route('dashboard', ['employee' => $employeeUser->id]),
            route('edata.me'),
            route('edata.me.attendance'),
            route('edata.attendance.index'),
            route('edata.attendance.index', ['status' => 'absent']),
            route('edata.people.index'),
            route('edata.people.create'),
            route('edata.people.show', $employeeUser->id),
            route('edata.people.edit', $employeeUser->id),
            route('roles.index'),
            route('roles.create'),
            route('roles.edit', $role->id),
            route('edata.clients.index'),
            route('edata.clients.index', ['q' => 'x', 'status' => 'active']),
            route('edata.clients.create'),
            route('edata.clients.show', $customer->id),
            route('edata.clients.edit', $customer->id),
            route('projects.index'),
            route('projects.index', ['view' => 'table']),
            route('projects.index', ['view' => 'board']),
            route('projects.index', ['view' => 'timeline']),
            route('projects.create'),
            route('projects.show', $project->id),
            route('projects.edit', $project->id),
            route('edata.tasks.index'),
            route('edata.tasks.index', ['view' => 'board']),
            route('edata.tasks.index', ['state' => 'attention']),
            route('edata.tasks.create'),
            route('edata.tasks.show', $task->id),
            route('edata.tasks.edit', $task->id),
            route('edata.invoices.index'),
            route('edata.invoices.index', ['state' => 'overdue']),
            route('edata.invoices.create'),
            route('edata.invoices.show', $invoice->id),
            route('edata.invoices.edit', $invoice->id),
            route('edata.invoices.print', $invoice->id),
            route('edata.products.index'),
            route('edata.products.create'),
            route('edata.products.edit', $product->id),
            route('edata.notifications.index'),
            route('edata.notifications.feed'),
            route('edata.audit.index'),
        ];
    }

    public function test_every_screen_opens_for_the_owner(): void
    {
        foreach ($this->urls() as $url) {
            $response = $this->actingAs($this->owner)->get($url);

            // The owner has no employee record, so there is no clock to show.
            if (str_contains($url, 'my/attendance')) {
                $response->assertRedirect(route('dashboard'));
                continue;
            }

            $this->assertSame(200, $response->getStatusCode(), 'Owner got ' . $response->getStatusCode() . ' on ' . $url);
            if (!str_contains($url, 'feed')) {
                $this->assertStringNotContainsString('ERPGo', $response->getContent(), $url);
            }
        }
    }

    public function test_no_screen_returns_a_server_error_for_a_plain_employee(): void
    {
        $user = $this->staff(['manage project', 'manage project task', 'view project task'], 'own', 'Plain Employee');
        $this->employeeFor($user);

        foreach ($this->urls() as $url) {
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            $this->assertLessThan(500, $status, 'Employee got ' . $status . ' on ' . $url);
        }
    }

    public function test_no_screen_returns_a_server_error_for_a_user_with_no_permissions_and_no_employee_record(): void
    {
        $user = $this->staff();

        foreach ($this->urls() as $url) {
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            $this->assertLessThan(500, $status, 'Bare user got ' . $status . ' on ' . $url);
        }
    }

    public function test_employee_home_is_their_own_space_and_owner_home_is_the_overview(): void
    {
        $employee = $this->staff(['manage project'], 'own');
        $this->actingAs($employee)->get(route('dashboard'))->assertRedirect(route('edata.me'));
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
    }

    public function test_sidebar_never_links_to_a_retired_module(): void
    {
        $html = $this->actingAs($this->owner)->get(route('edata.people.index'))->getContent();
        preg_match('/<aside class="ed-side".*?<\/aside>/s', $html, $sidebar);

        foreach (['/leads', '/deals', '/form_builder', '/contract', '/pos', '/support', '/zoom-meeting', '/chats', '/notification-templates'] as $path) {
            $this->assertStringNotContainsString($path, $sidebar[0], 'Sidebar links to ' . $path);
        }
    }

    public function test_user_can_add_a_person_who_then_logs_in_with_their_own_account(): void
    {
        $role = $this->staff()->roles->first();

        $this->actingAs($this->owner)->post(route('edata.people.store'), [
            'name' => 'New Hire', 'email' => 'new-hire@test.local', 'role' => $role->id,
            'employment_status' => 'active', 'password' => 'Str0ngPass!', 'password_confirmation' => 'Str0ngPass!',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'new-hire@test.local')->firstOrFail();
        $this->assertSame(1, (int) $user->is_enable_login);
        $this->assertTrue($user->hasRole($role->name));
        $this->assertNotNull($user->employee);
        $this->assertSame($this->company, (int) $user->created_by);
    }

    public function test_client_and_project_can_be_created_and_linked(): void
    {
        $this->actingAs($this->owner)->post(route('edata.clients.store'), ['name' => 'شركة الاختبار', 'crm_status' => 'active', 'industry' => 'تعليم'])->assertSessionHasNoErrors();
        $client = \App\Models\Customer::where('name', 'شركة الاختبار')->firstOrFail();

        $this->actingAs($this->owner)->post(route('projects.store'), ['project_name' => 'مشروع مرتبط', 'customer_id' => $client->id, 'status' => 'new'])->assertSessionHasNoErrors();
        $project = \App\Models\Project::where('project_name', 'مشروع مرتبط')->firstOrFail();

        $this->assertSame((int) $client->id, (int) $project->customer_id);
        $this->actingAs($this->owner)->get(route('edata.clients.show', [$client->id, 'tab' => 'projects']))->assertOk()->assertSee('مشروع مرتبط');
    }
}
