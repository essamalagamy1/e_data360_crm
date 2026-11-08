<?php

namespace Tests\Feature\Edata;

use App\Models\AttendanceEmployee;
use App\Models\EdataAuditLog;
use App\Support\Edata\AttendanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceTest extends EdataTestCase
{
    private function at(string $cairoTime): void
    {
        Carbon::setTestNow(Carbon::parse($cairoTime, 'Africa/Cairo')->utc());
    }

    public function test_clock_in_stores_exact_utc_moment_and_local_columns(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $this->at('2026-11-02 09:40:00'); // Monday

        $this->actingAs($user)->post(route('edata.me.clock-in'))->assertSessionHas('ed_moment');

        $row = AttendanceEmployee::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('2026-11-02', Carbon::parse($row->date)->toDateString());
        $this->assertSame('09:40:00', $row->clock_in);
        $this->assertSame('00:00:00', $row->clock_out);
        $this->assertSame('2026-11-02 07:40:00', Carbon::parse($row->clock_in_at)->format('Y-m-d H:i:s'));
        $this->assertSame((int) $employee->id, (int) $row->open_key);
        $this->assertSame('00:40:00', $row->late);
        $this->assertSame($this->company, (int) $row->created_by);
    }

    public function test_second_clock_in_during_an_open_period_is_refused(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $this->at('2026-11-02 09:00:00');

        $this->actingAs($user)->post(route('edata.me.clock-in'));
        $this->actingAs($user)->post(route('edata.me.clock-in'))->assertSessionHas('error');

        $this->assertSame(1, AttendanceEmployee::where('employee_id', $employee->id)->count());
    }

    public function test_clock_out_without_open_session_is_refused(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);

        $this->actingAs($user)->post(route('edata.me.clock-out'))->assertSessionHas('error');

        $this->assertSame(0, AttendanceEmployee::where('employee_id', $employee->id)->count());
    }

    public function test_clock_out_saves_actual_hours_and_closes_the_session(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $this->at('2026-11-02 09:00:00');
        $this->actingAs($user)->post(route('edata.me.clock-in'));

        $this->at('2026-11-02 17:30:00');
        $this->actingAs($user)->post(route('edata.me.clock-out'))->assertSessionHas('ed_moment');

        $row = AttendanceEmployee::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(8 * 3600 + 1800, (int) $row->worked_seconds);
        $this->assertNull($row->open_key);
        $this->assertSame('17:30:00', $row->clock_out);
        $this->assertSame('00:30:00', $row->overtime);
    }

    public function test_timer_comes_from_the_server_not_the_open_page(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $this->at('2026-11-02 09:00:00');
        $service = AttendanceService::for($this->company);
        $service->clockIn($employee);

        // The page was closed for three hours; nothing ran on the client in between.
        $this->at('2026-11-02 12:00:00');
        $state = AttendanceService::for($this->company)->stateFor($employee);

        $this->assertTrue($state['is_open']);
        $this->assertSame(3 * 3600, $state['running_seconds']);
        $this->assertSame('working', $state['status']);
    }

    public function test_date_follows_company_timezone_not_server_utc(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);

        // 22:30 UTC on the 1st is already 00:30 on the 2nd in Cairo.
        Carbon::setTestNow(Carbon::parse('2026-11-01 22:30:00', 'UTC'));
        $row = AttendanceService::for($this->company)->clockIn($employee);

        $this->assertSame('2026-11-02', Carbon::parse($row->date)->toDateString());
        $this->assertSame('00:30:00', $row->clock_in);
    }

    public function test_forgotten_session_does_not_block_next_day_and_shows_as_missing_clock_out(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $service = AttendanceService::for($this->company);

        $this->at('2026-11-02 09:00:00');
        $service->clockIn($employee);

        $this->at('2026-11-03 09:00:00');
        $service->clockIn($employee);

        $this->assertSame(2, AttendanceEmployee::where('employee_id', $employee->id)->count());

        $days = $service->report(collect([$employee]), '2026-11-02', '2026-11-02');
        $this->assertSame('missing_out', $days->first()['status']);
    }

    public function test_employee_cannot_close_someone_elses_session_through_the_old_url(): void
    {
        $victim = $this->staff();
        $victimEmployee = $this->employeeFor($victim);
        $attacker = $this->staff();
        $this->employeeFor($attacker);

        $this->at('2026-11-02 09:00:00');
        $row = AttendanceService::for($this->company)->clockIn($victimEmployee);

        $this->at('2026-11-02 10:00:00');
        $this->actingAs($attacker)->put('/attendanceemployee/' . $row->id, ['date' => '2020-01-01'])->assertSessionHas('error');

        $fresh = $row->fresh();
        $this->assertSame('00:00:00', $fresh->clock_out);
        $this->assertSame('2026-11-02', Carbon::parse($fresh->date)->toDateString());
    }

    public function test_employee_without_permission_cannot_edit_or_delete_attendance(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $this->at('2026-11-02 09:00:00');
        $row = AttendanceService::for($this->company)->clockIn($employee);

        $this->actingAs($user)->put(route('edata.attendance.update', $row->id), ['clock_in' => '08:00', 'reason' => 'trying'])->assertForbidden();
        $this->actingAs($user)->delete(route('edata.attendance.destroy', $row->id), ['reason' => 'trying'])->assertForbidden();

        $this->assertSame('09:00:00', $row->fresh()->clock_in);
    }

    public function test_admin_correction_requires_a_reason_and_lands_in_the_audit_log(): void
    {
        $admin = $this->staff(['manage attendance', 'edit attendance'], 'all');
        $employee = $this->employeeFor($this->staff());
        $this->at('2026-11-02 09:30:00');
        $row = AttendanceService::for($this->company)->clockIn($employee);

        $this->actingAs($admin)->put(route('edata.attendance.update', $row->id), ['clock_in' => '09:00'])->assertSessionHasErrors('reason');
        $this->assertSame('09:30:00', $row->fresh()->clock_in);

        $this->at('2026-11-02 18:00:00');
        $this->actingAs($admin)->put(route('edata.attendance.update', $row->id), [
            'clock_in' => '09:00', 'clock_out' => '17:00', 'reason' => 'نسي التسجيل',
        ])->assertSessionHas('success');

        $fresh = $row->fresh();
        $this->assertSame('09:00:00', $fresh->clock_in);
        $this->assertSame(8 * 3600, (int) $fresh->worked_seconds);

        $log = EdataAuditLog::where('entity_type', 'attendance')->where('entity_id', $row->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $log->user_id);
        $this->assertSame('نسي التسجيل', $log->note);
        $this->assertSame('09:30:00', $log->old_values['clock_in']);
        $this->assertSame('09:00:00', $log->new_values['clock_in']);
    }

    public function test_statuses_for_absent_rest_day_holiday_leave_and_late(): void
    {
        $employee = $this->employeeFor($this->staff());
        $service = AttendanceService::for($this->company);

        // Monday 2 Nov: late. Tuesday: absent. Wednesday: holiday. Thursday: leave. Friday: rest.
        $this->at('2026-11-02 10:00:00');
        $service->clockIn($employee);
        $this->at('2026-11-02 18:00:00');
        $service->clockOut($employee);

        DB::table('holidays')->insert(['date' => '2026-11-04', 'end_date' => '2026-11-04', 'occasion' => 'x', 'created_by' => $this->company, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leaves')->insert([
            'employee_id' => $employee->id, 'leave_type_id' => 1, 'applied_on' => '2026-11-01', 'start_date' => '2026-11-05', 'end_date' => '2026-11-05',
            'total_leave_days' => '1', 'leave_reason' => 'x', 'status' => 'Approved', 'created_by' => $this->company, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->at('2026-11-07 12:00:00');
        $days = AttendanceService::for($this->company)->report(collect([$employee]), '2026-11-02', '2026-11-06')->keyBy('date');

        $this->assertSame('late', $days['2026-11-02']['status']);
        $this->assertSame(60, $days['2026-11-02']['late_minutes']);
        $this->assertSame('absent', $days['2026-11-03']['status']);
        $this->assertSame('holiday', $days['2026-11-04']['status']);
        $this->assertSame('leave', $days['2026-11-05']['status']);
        $this->assertSame('weekend', $days['2026-11-06']['status']);
    }

    public function test_grace_period_is_not_counted_as_late_and_overtime_is_detected(): void
    {
        $employee = $this->employeeFor($this->staff());
        $service = AttendanceService::for($this->company);

        $this->at('2026-11-02 09:10:00');
        $service->clockIn($employee);
        $this->at('2026-11-02 19:10:00');
        $service->clockOut($employee);

        $day = $service->report(collect([$employee]), '2026-11-02', '2026-11-02')->first();

        $this->assertSame(0, $day['late_minutes']);
        $this->assertSame('overtime', $day['status']);
        $this->assertSame(2 * 3600, $day['overtime_seconds']);
    }
}
