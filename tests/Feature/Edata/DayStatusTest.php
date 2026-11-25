<?php

namespace Tests\Feature\Edata;

use App\Models\EdataAuditLog;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Notification;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\EmployeeServices;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DayStatusTest extends EdataTestCase
{
    private const DAY = '2026-11-03'; // a past Tuesday

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-11-05 18:00', 'Africa/Cairo')->utc());
    }

    private function dayStatus(Employee $employee, string $date = self::DAY): string
    {
        return AttendanceService::for($this->company)->report(collect([$employee]), $date, $date)->first()['status'];
    }

    private function setState(Employee $employee, string $state, array $extra = [])
    {
        return $this->post(route('edata.attendance.day'), ['employee_id' => $employee->id, 'date' => self::DAY, 'state' => $state, 'reason' => 'تصحيح من الإدارة'] + $extra);
    }

    private function type(): int
    {
        return (int) DB::table('leave_types')->where('created_by', $this->company)->value('id');
    }

    public function test_absence_becomes_attendance(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $this->assertSame('absent', $this->dayStatus($employee));

        $this->actingAs($this->owner)->get(route('edata.attendance.index', ['employee' => $employee->id]))->assertOk()->assertSee('data-day-edit', false);
        $this->setState($employee, 'present', ['clock_in' => '09:00', 'clock_out' => '17:00'])->assertSessionHas('success');

        $this->assertSame('present', $this->dayStatus($employee));
        $this->assertTrue(Notification::where('user_id', $user->id)->where('type', 'attendance_changed')->exists());
        $this->assertTrue(EdataAuditLog::where('note', 'تصحيح من الإدارة')->exists());

        // A day with a clock-in is edited through its own record.
        $this->setState($employee, 'absent')->assertSessionHas('error');
        $this->assertSame('present', $this->dayStatus($employee));
    }

    public function test_absence_becomes_leave_and_returns_to_the_balance_when_undone(): void
    {
        $employee = $this->employeeFor($this->staff());
        DB::table('edata_leave_allowances')->insert(['company_id' => $this->company, 'employee_id' => $employee->id, 'leave_type_id' => $this->type(), 'days' => 10]);
        $left = fn () => EmployeeServices::leaveBalances($employee, $this->company, Carbon::now())->firstWhere('id', $this->type())->left;

        $this->actingAs($this->owner);
        $this->setState($employee, 'leave')->assertSessionHasErrors('leave_type_id');
        $this->setState($employee, 'leave', ['leave_type_id' => $this->type()])->assertSessionHas('success');
        $this->assertSame('leave', $this->dayStatus($employee));
        $this->assertEquals(9, $left());

        // Saving it again does not count the day twice.
        $this->setState($employee, 'leave', ['leave_type_id' => $this->type()])->assertSessionHas('success');
        $this->assertEquals(9, $left());

        $this->setState($employee, 'excused')->assertSessionHas('success');
        $this->assertSame('excused', $this->dayStatus($employee));
        $this->assertEquals(10, $left());

        $this->setState($employee, 'day_off')->assertSessionHas('success');
        $totals = AttendanceService::for($this->company)->totals(AttendanceService::for($this->company)->report(collect([$employee]), self::DAY, self::DAY));
        $this->assertSame(0, $totals['absent']);
        $this->assertSame(0, $totals['expected']);

        $this->setState($employee, 'auto')->assertSessionHas('success');
        $this->assertSame('absent', $this->dayStatus($employee));
        $this->assertSame(0, DB::table('edata_day_statuses')->where('employee_id', $employee->id)->count());
    }

    public function test_a_day_inside_a_requested_leave_is_left_alone(): void
    {
        $employee = $this->employeeFor($this->staff());
        $leave = Leave::create(['employee_id' => $employee->id, 'leave_type_id' => $this->type(), 'applied_on' => '2026-11-01', 'start_date' => '2026-11-02', 'end_date' => '2026-11-04', 'total_leave_days' => '3', 'leave_reason' => 'سفر', 'remark' => '', 'status' => 'Approved', 'created_by' => $this->company]);

        $this->actingAs($this->owner);
        $this->setState($employee, 'absent')->assertSessionHas('error');
        $this->assertSame('leave', $this->dayStatus($employee));
        $this->assertNotNull(Leave::find($leave->id));
    }

    public function test_future_days_and_people_without_permission_are_refused(): void
    {
        $employee = $this->employeeFor($this->staff());
        $this->actingAs($this->owner)->post(route('edata.attendance.day'), ['employee_id' => $employee->id, 'date' => '2026-11-09', 'state' => 'excused', 'reason' => 'سبب'])->assertSessionHas('error');

        $this->actingAs($this->staff(['manage attendance'], 'all'));
        $this->setState($employee, 'excused')->assertForbidden();
        // An editor limited to their own records cannot reach someone else's day.
        $this->actingAs($this->staff(['manage attendance', 'edit attendance'], 'own'));
        $this->setState($employee, 'excused')->assertNotFound();
        $this->assertSame('absent', $this->dayStatus($employee));
    }
}
