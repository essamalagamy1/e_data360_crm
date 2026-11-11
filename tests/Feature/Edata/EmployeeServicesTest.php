<?php

namespace Tests\Feature\Edata;

use App\Models\AttendanceEmployee;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Notification;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\Edata\AttendanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeServicesTest extends EdataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        // Thursday 5 Nov 2026, 09:00 in Cairo.
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00', 'Africa/Cairo')->utc());
    }

    /** The employee services every role gets by default; tests opt out through parent::staff(). */
    private const SELF = ['create self request', 'use day plan', 'use task timer', 'view month summary'];

    protected function staff(array $permissions = [], string $scope = 'own', ?string $name = null): User
    {
        return parent::staff(array_merge(self::SELF, $permissions), $scope, $name);
    }

    public function test_services_follow_the_role_permissions(): void
    {
        $user = parent::staff();
        $employee = $this->employeeFor($user);
        $task = $this->task($user, 'Locked task');

        $this->actingAs($user)->get(route('edata.me'))->assertOk()
            ->assertDontSee('ملخص الشهر')->assertDontSee('خطة اليوم')->assertDontSee(route('edata.timer.start', $task->id), false)->assertDontSee(route('edata.my.leave'), false);
        $this->post(route('edata.my.permission'), ['date' => '2026-11-05', 'time_from' => '13:00', 'time_to' => '14:00', 'reason' => 'ظرف'])->assertForbidden();
        $this->post(route('edata.my.plan'), ['tasks' => [$task->id]])->assertForbidden();
        $this->post(route('edata.timer.start', $task->id))->assertForbidden();
        $this->assertFalse(DB::table('edata_requests')->where('employee_id', $employee->id)->exists());

        // Daily reports and the requests inbox each have their own permission.
        $manager = parent::staff(['manage attendance', 'edit attendance']);
        $this->actingAs($manager)->get(route('edata.daily.index'))->assertForbidden();
        $this->get(route('edata.requests.index'))->assertForbidden();
        $lead = parent::staff(['view daily report', 'manage leave']);
        $this->actingAs($lead)->get(route('edata.daily.index'))->assertOk();
        $this->get(route('edata.requests.index'))->assertOk();
    }

    public function test_roles_screen_lists_the_services(): void
    {
        $this->actingAs($this->owner)->get(route('roles.create'))->assertOk()
            ->assertSee('actions[self_requests][view]', false)->assertSee('actions[day_plan][view]', false)->assertSee('actions[month_summary][view]', false)
            ->assertSee('actions[daily_reports][view]', false)->assertSee('actions[announcements][create]', false)->assertSee('actions[task_timer][view]', false);
    }

    public function test_invoices_only_even_with_company_wide_scopes(): void
    {
        $this->invoice(1500);
        // Every scope is the whole company, but the role only holds the invoices permission.
        $person = parent::staff(['manage invoice', 'show invoice'], 'all');

        $this->actingAs($person)->get(route('dashboard'))->assertOk()
            ->assertSee('فواتير الشهر الحالي')->assertSee('الفواتير والتحصيل')->assertDontSee('مشاريع تقترب مواعيدها')->assertDontSee('أُنجزت في الفترة');
    }

    private function task(User $user, string $name = 'Task'): ProjectTask
    {
        $project = $this->project([], [$user]);

        return ProjectTask::create(['name' => $name, 'project_id' => $project->id, 'assign_to' => (string) $user->id, 'priority' => 'low', 'created_by' => $this->company]);
    }

    public function test_leave_request_goes_to_the_owner_and_the_answer_comes_back(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $type = DB::table('leave_types')->where('created_by', $this->company)->value('id');

        $this->actingAs($user)->post(route('edata.my.leave'), [
            'leave_type_id' => $type, 'start_date' => '2026-11-10', 'end_date' => '2026-11-11', 'reason' => 'سفر عائلي',
        ])->assertSessionHas('success');

        $leave = Leave::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('Pending', $leave->status);
        $this->assertSame('2', (string) $leave->total_leave_days);
        $this->assertTrue(Notification::where('user_id', $this->owner->id)->where('type', 'request_new')->exists());

        $this->actingAs($this->owner)->post(route('edata.requests.decide', ['leave', $leave->id]), ['decision' => 'approve'])->assertSessionHas('success');
        $this->assertSame('Approved', $leave->fresh()->status);
        $this->assertTrue(Notification::where('user_id', $user->id)->where('type', 'request_decided')->exists());

        // A second answer to the same request is refused.
        $this->post(route('edata.requests.decide', ['leave', $leave->id]), ['decision' => 'reject'])->assertSessionHas('error');
        $this->assertSame('Approved', $leave->fresh()->status);
    }

    public function test_an_approved_correction_writes_the_day(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);

        $this->actingAs($user)->post(route('edata.my.correction'), [
            'date' => '2026-11-04', 'time_from' => '09:05', 'time_to' => '17:00', 'reason' => 'نسيت تسجيل الحضور',
        ])->assertSessionHas('success');
        $id = DB::table('edata_requests')->where('employee_id', $employee->id)->value('id');
        $this->assertFalse(AttendanceEmployee::where('employee_id', $employee->id)->whereDate('date', '2026-11-04')->exists());

        $this->actingAs($this->owner)->post(route('edata.requests.decide', ['request', $id]), ['decision' => 'approve'])->assertSessionHas('success');

        $this->assertTrue(AttendanceEmployee::where('employee_id', $employee->id)->whereDate('date', '2026-11-04')->exists());
        $this->assertSame('approved', DB::table('edata_requests')->where('id', $id)->value('status'));
    }

    public function test_an_approved_permission_removes_the_late_mark(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 11:00', 'Africa/Cairo')->utc());
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $this->actingAs($user)->post(route('edata.me.clock-in'));

        $service = AttendanceService::for($this->company);
        $day = $service->report(collect([$employee]), '2026-11-05', '2026-11-05')->first();
        $this->assertContains('late', $day['flags']);

        $this->post(route('edata.my.permission'), ['date' => '2026-11-05', 'time_from' => '09:00', 'time_to' => '11:00', 'reason' => 'موعد طبيب'])->assertSessionHas('success');
        $id = DB::table('edata_requests')->where('employee_id', $employee->id)->value('id');
        $this->actingAs($this->owner)->post(route('edata.requests.decide', ['request', $id]), ['decision' => 'approve']);

        $day = $service->report(collect([$employee]), '2026-11-05', '2026-11-05')->first();
        $this->assertNotContains('late', $day['flags']);
        $this->assertContains('permission', $day['flags']);
    }

    public function test_a_plain_employee_cannot_approve(): void
    {
        $user = $this->staff();
        $this->employeeFor($user);
        $other = $this->staff();
        $otherEmployee = $this->employeeFor($other);
        $this->actingAs($other)->post(route('edata.my.permission'), ['date' => '2026-11-05', 'time_from' => '13:00', 'time_to' => '14:00', 'reason' => 'ظرف']);
        $id = DB::table('edata_requests')->where('employee_id', $otherEmployee->id)->value('id');

        $this->actingAs($user)->get(route('edata.requests.index'))->assertForbidden();
        $this->post(route('edata.requests.decide', ['request', $id]), ['decision' => 'approve'])->assertForbidden();
        $this->assertSame('pending', DB::table('edata_requests')->where('id', $id)->value('status'));
    }

    public function test_day_plan_and_end_of_day_report(): void
    {
        $user = $this->staff(['manage project task']);
        $employee = $this->employeeFor($user);
        $mine = $this->task($user, 'كتابة التقرير');
        $notMine = ProjectTask::create(['name' => 'Other', 'project_id' => $mine->project_id, 'assign_to' => '999999', 'priority' => 'low', 'created_by' => $this->company]);

        $this->actingAs($user)->post(route('edata.my.plan'), ['tasks' => [$notMine->id]])->assertSessionHasErrors('tasks.0');
        $this->post(route('edata.my.plan'), ['tasks' => [$mine->id]])->assertSessionHas('success');
        $this->get(route('edata.me'))->assertOk()->assertSee('خطة اليوم')->assertSee('كتابة التقرير');

        $this->post(route('edata.me.clock-in'));
        Carbon::setTestNow(Carbon::now()->addHours(8));
        $this->post(route('edata.me.clock-out'), ['report' => 'خلصت مسودة التقرير']);

        $log = DB::table('edata_daily_logs')->where('employee_id', $employee->id)->first();
        $this->assertSame('خلصت مسودة التقرير', $log->report);
        $this->assertSame([$mine->id], json_decode($log->plan, true));

        $this->actingAs($this->owner)->get(route('edata.daily.index', ['date' => '2026-11-05']))->assertOk()->assertSee('خلصت مسودة التقرير');
    }

    public function test_task_timer_runs_one_at_a_time(): void
    {
        $user = $this->staff();
        $this->employeeFor($user);
        $first = $this->task($user, 'First');
        $second = $this->task($user, 'Second');

        $this->actingAs($user)->post(route('edata.timer.start', $first->id))->assertSessionHas('success');
        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        $this->post(route('edata.timer.start', $second->id));

        $rows = DB::table('edata_task_timers')->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1800, (int) $rows[0]->seconds);
        $this->assertNull($rows[1]->stopped_at);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->post(route('edata.timer.stop'))->assertSessionHas('success');
        $this->assertSame(600, (int) DB::table('edata_task_timers')->where('id', $rows[1]->id)->value('seconds'));

        $this->get(route('edata.tasks.show', $first->id))->assertOk()->assertSee('مؤقت المهمة');
    }

    public function test_cannot_time_a_task_outside_your_scope(): void
    {
        $user = $this->staff();
        $this->employeeFor($user);
        $someone = $this->staff();
        $theirs = $this->task($someone);

        $this->actingAs($user)->post(route('edata.timer.start', $theirs->id))->assertNotFound();
        $this->assertSame(0, DB::table('edata_task_timers')->where('user_id', $user->id)->count());
    }

    public function test_announcement_reaches_the_team_and_tracks_reads(): void
    {
        $user = $this->staff();
        $this->employeeFor($user);

        $this->actingAs($user)->post(route('edata.announcements.store'), ['title' => 'x'])->assertForbidden();

        $this->actingAs($this->owner)->post(route('edata.announcements.store'), ['title' => 'اجتماع الأحد', 'body' => 'الساعة 10'])->assertSessionHas('success');
        $id = DB::table('edata_announcements')->where('company_id', $this->company)->value('id');
        $this->assertTrue(Notification::where('user_id', $user->id)->where('type', 'announcement')->exists());

        $this->actingAs($user)->get(route('edata.me'))->assertOk()->assertSee('اجتماع الأحد');
        $this->post(route('edata.announcements.read', $id));
        $this->assertTrue(DB::table('edata_announcement_reads')->where('announcement_id', $id)->where('user_id', $user->id)->exists());

        $this->actingAs($this->owner)->post(route('edata.announcements.archive', $id));
        $this->actingAs($user)->get(route('edata.me'))->assertDontSee('اجتماع الأحد');
    }

    public function test_month_summary_shows_on_my_space(): void
    {
        $user = $this->staff();
        $this->employeeFor($user);

        $this->actingAs($user)->get(route('edata.me'))->assertOk()->assertSee('ملخص الشهر')->assertSee('طلباتي');
        $this->get(route('edata.me', ['m' => '2026-10']))->assertOk()->assertSee('ملخص الشهر');
    }

    public function test_invoices_permission_opens_a_finance_dashboard(): void
    {
        $this->invoice(1500);
        // Sees only their own projects, but every invoice: the person handling invoices.
        $accountant = $this->staff(['manage invoice', 'show invoice']);
        DB::table('edata_role_scopes')->where('role_id', $accountant->roles->first()->id)->where('module', 'invoices')->update(['scope' => 'all']);
        \App\Support\Edata\Access::flushCache();
        $plain = $this->staff();

        $this->actingAs($accountant)->get(route('dashboard'))->assertOk()
            ->assertSee('فواتير الشهر الحالي')->assertSee('إجمالي فواتير الشهر')->assertDontSee('مشاريع تقترب مواعيدها');
        $this->actingAs($plain)->get(route('dashboard'))->assertRedirect(route('edata.me'));
    }

    public function test_sidebar_has_no_more_menu(): void
    {
        $this->actingAs($this->owner)->get(route('edata.me'))->assertOk()->assertDontSee('edSideMorePanel', false)->assertSee(route('edata.requests.index'), false);
    }

    public function test_person_without_history_can_be_deleted(): void
    {
        $person = $this->staff();
        $this->employeeFor($person);

        $this->actingAs($this->owner)->delete(route('edata.people.destroy', $person->id))->assertSessionHas('success');
        $this->assertNull(User::find($person->id));
        $this->assertFalse(Employee::where('user_id', $person->id)->exists());
    }

    public function test_person_with_history_gets_an_end_of_service_instead(): void
    {
        $person = $this->staff([], 'own', 'Ended Person');
        $employee = $this->employeeFor($person);
        $this->task($person);

        $this->actingAs($this->owner)->delete(route('edata.people.destroy', $person->id))->assertSessionHas('error');
        $this->assertNotNull(User::find($person->id));

        $this->post(route('edata.people.end', $person->id))->assertSessionHas('success');
        $this->assertSame('ended', $employee->fresh()->employment_status);
        $this->assertSame(0, (int) $person->fresh()->is_enable_login);

        $row = 'href="' . route('edata.people.show', $person->id) . '"';
        $this->get(route('edata.people.index'))->assertOk()->assertDontSee($row, false);
        $this->get(route('edata.people.index', ['employment' => 'ended']))->assertOk()->assertSee($row, false);
    }
}
