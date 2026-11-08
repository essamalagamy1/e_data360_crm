<?php

namespace Tests\Feature\Edata;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccessTest extends EdataTestCase
{
    public function test_employee_sees_only_projects_they_belong_to(): void
    {
        $user = $this->staff(['manage project', 'view project']);
        $mine = $this->project([], [$user]);
        $other = $this->project();

        $this->actingAs($user)->get(route('projects.show', $mine->id))->assertOk();
        $this->actingAs($user)->get(route('projects.show', $other->id))->assertNotFound();
    }

    public function test_team_scope_does_not_open_other_teams(): void
    {
        $pm = $this->staff(['manage project', 'view project', 'edit project'], 'team');
        $managed = $this->project(['manager_id' => $pm->id]);
        $other = $this->project();

        $this->actingAs($pm)->get(route('projects.show', $managed->id))->assertOk();
        $this->actingAs($pm)->put(route('projects.update', $other->id), ['project_name' => 'hacked', 'status' => 'new'])->assertNotFound();
        $this->assertNotSame('hacked', $other->fresh()->project_name);
    }

    public function test_employee_cannot_reach_invoices_clients_or_roles_by_url(): void
    {
        $user = $this->staff(['manage project']);
        $invoice = $this->invoice();

        $this->actingAs($user)->get(route('edata.invoices.index'))->assertForbidden();
        $this->actingAs($user)->get(route('edata.invoices.show', $invoice->id))->assertForbidden();
        $this->actingAs($user)->get(route('edata.clients.index'))->assertForbidden();
        $this->actingAs($user)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($user)->get(route('edata.audit.index'))->assertForbidden();
    }

    public function test_task_scope_own_hides_tasks_assigned_to_others(): void
    {
        $user = $this->staff(['manage project task', 'view project task']);
        $project = $this->project([], [$user]);
        $mine = ProjectTask::create(['name' => 'Mine', 'project_id' => $project->id, 'assign_to' => (string) $user->id, 'priority' => 'low', 'created_by' => $this->company]);
        $theirs = ProjectTask::create(['name' => 'Theirs', 'project_id' => $project->id, 'assign_to' => '999999', 'priority' => 'low', 'created_by' => $this->company]);

        $this->actingAs($user)->get(route('edata.tasks.show', $mine->id))->assertOk();
        $this->actingAs($user)->get(route('edata.tasks.show', $theirs->id))->assertNotFound();
    }

    public function test_assignee_can_move_own_task_without_edit_permission_but_not_others(): void
    {
        $user = $this->staff(['manage project task']);
        $project = $this->project([], [$user]);
        $stage = (int) DB::table('task_stages')->where('created_by', $this->company)->orderBy('order')->value('id');
        $mine = ProjectTask::create(['name' => 'Mine', 'project_id' => $project->id, 'assign_to' => (string) $user->id, 'priority' => 'low', 'stage_id' => $stage, 'created_by' => $this->company]);
        $done = (int) DB::table('task_stages')->where('created_by', $this->company)->orderByDesc('order')->value('id');

        $this->actingAs($user)->postJson(route('edata.tasks.move', $mine->id), ['stage_id' => $done])->assertOk();
        $this->assertSame(1, (int) $mine->fresh()->is_complete);

        $other = ProjectTask::create(['name' => 'Other', 'project_id' => $project->id, 'assign_to' => '999999', 'priority' => 'low', 'stage_id' => $stage, 'created_by' => $this->company]);
        $this->actingAs($user)->postJson(route('edata.tasks.move', $other->id), ['stage_id' => $done])->assertNotFound();
    }

    public function test_another_companys_records_are_invisible_even_to_their_owner(): void
    {
        $otherCompany = new User();
        $otherCompany->name = 'Other Co';
        $otherCompany->email = 'other-co@test.local';
        $otherCompany->password = Hash::make('secret-pass');
        $otherCompany->type = 'company';
        $otherCompany->plan = 1;
        $otherCompany->lang = 'ar';
        $otherCompany->created_by = 1;
        $otherCompany->email_verified_at = now();
        $otherCompany->save();
        $otherCompany->assignRole('company');

        $theirClient = new Customer();
        $theirClient->forceFill(['customer_id' => 1, 'name' => 'Their client', 'created_by' => $otherCompany->id, 'lang' => 'ar', 'avatar' => '']);
        $theirClient->save();

        $this->actingAs($this->owner)->get(route('edata.clients.show', $theirClient->id))->assertNotFound();
    }

    public function test_role_change_applies_on_the_server_immediately(): void
    {
        $user = $this->staff(['manage invoice']);
        $this->actingAs($user)->get(route('edata.invoices.index'))->assertOk();

        $user->roles->first()->syncPermissions([]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh())->get(route('edata.invoices.index'))->assertForbidden();
    }

    public function test_nobody_can_grant_a_permission_they_do_not_hold(): void
    {
        $manager = $this->staff(['manage role', 'edit role']);
        $target = $this->staff();
        $role = $target->roles->first();

        $this->actingAs($manager)->put(route('roles.update', $role->id), [
            'name' => $role->name,
            'actions' => ['invoices' => ['view' => 1, 'delete' => 1]],
        ])->assertSessionHasNoErrors();

        $role->refresh();
        $this->assertFalse($role->hasPermissionTo('manage invoice'));
        $this->assertFalse($role->hasPermissionTo('delete invoice'));
    }

    public function test_retired_modules_redirect_and_their_rows_stay_in_the_database(): void
    {
        $user = $this->owner;
        DB::table('leads')->insert(['name' => 'Old lead', 'email' => 'l@test.local', 'subject' => 's', 'user_id' => $user->id, 'pipeline_id' => 1, 'stage_id' => 1, 'created_by' => $user->id, 'date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        foreach (['/leads', '/leads/list', '/deals', '/form_builder', '/contract', '/pos', '/pos-dashboard', '/support', '/zoom-meeting', '/chats', '/notification-templates'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'));
        }

        $this->assertSame(1, DB::table('leads')->where('email', 'l@test.local')->count());
    }

    public function test_routes_that_were_public_now_need_a_login(): void
    {
        $this->get('/config-cache')->assertRedirect();
        $this->get('/storage-link')->assertRedirect();
        $this->post('/user-unable', ['id' => $this->owner->id, 'is_disable' => 0])->assertRedirect();
        $this->assertSame(1, (int) $this->owner->fresh()->is_disable);

        $employee = $this->staff();
        $this->actingAs($employee)->get('/config-cache')->assertForbidden();
    }

    public function test_user_cannot_reset_another_users_password_without_permission(): void
    {
        $victim = $this->staff();
        $attacker = $this->staff();
        $hash = $victim->password;

        $this->actingAs($attacker)->post('/user-reset-password/' . $victim->id, ['password' => 'x', 'password_confirmation' => 'x'])->assertForbidden();
        $this->actingAs($attacker)->post('/user-unable', ['id' => $victim->id, 'is_disable' => 0, 'company_id' => $this->company])->assertForbidden();
        $this->actingAs($attacker)->post(route('edata.people.password', $victim->id), ['password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertForbidden();

        $this->assertSame($hash, $victim->fresh()->password);
    }

    public function test_manager_cannot_touch_the_owner_account(): void
    {
        $manager = $this->staff(['manage user', 'edit user']);
        $hash = $this->owner->password;

        $this->actingAs($manager)->post(route('edata.people.password', $this->owner->id), ['password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertForbidden();
        $this->actingAs($manager)->post(route('edata.people.access', $this->owner->id))->assertForbidden();

        $this->assertSame($hash, $this->owner->fresh()->password);
    }

    public function test_person_with_history_cannot_be_deleted_only_disabled(): void
    {
        $person = $this->staff();
        $this->project([], [$person]);

        $this->actingAs($this->owner)->delete('/users/' . $person->id)->assertSessionHas('error');
        $this->assertNotNull($person->fresh());

        $this->actingAs($this->owner)->post(route('edata.people.access', $person->id))->assertSessionHas('success');
        $this->assertSame(0, (int) $person->fresh()->is_enable_login);
    }

    public function test_project_with_tasks_cannot_be_deleted(): void
    {
        $project = $this->project();
        ProjectTask::create(['name' => 'T', 'project_id' => $project->id, 'priority' => 'low', 'created_by' => $this->company]);

        $this->actingAs($this->owner)->delete(route('projects.destroy', $project->id))->assertSessionHas('error');

        $this->assertNotNull(Project::find($project->id));
    }

    public function test_client_with_invoices_can_be_archived_but_not_deleted(): void
    {
        $invoice = $this->invoice();
        $client = Customer::find($invoice->customer_id);

        $this->actingAs($this->owner)->delete('/customer/' . $client->id)->assertSessionHas('error');
        $this->assertNotNull(Customer::find($client->id));

        $this->actingAs($this->owner)->post(route('edata.clients.archive', $client->id))->assertSessionHas('success');
        $this->assertNotNull($client->fresh()->archived_at);
        $this->assertNotNull($invoice->fresh());
    }
}
