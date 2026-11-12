<?php

namespace Tests\Feature\Edata;

use App\Models\Employee;
use App\Support\Edata\AttendanceService;
use Carbon\Carbon;

class PartTimeTest extends EdataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        // Thursday 5 Nov 2026, 18:00 in Cairo: Sunday 1 Nov to Thursday 5 Nov are past days.
        Carbon::setTestNow(Carbon::parse('2026-11-05 18:00', 'Africa/Cairo')->utc());
    }

    private function statuses(Employee $employee): array
    {
        $days = AttendanceService::for($this->company)
            ->report(collect([$employee]), '2026-11-01', '2026-11-05')
            ->pluck('status', 'date')->all();
        ksort($days);

        return $days;
    }

    public function test_part_time_is_only_absent_on_their_own_days(): void
    {
        $full = $this->employeeFor($this->staff());
        $part = $this->employeeFor($this->staff());
        $part->edata_work_days = '0,2,3'; // Sunday, Tuesday, Wednesday
        $part->save();

        $this->assertSame(array_fill_keys(['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'], 'absent'), $this->statuses($full));
        $this->assertSame([
            '2026-11-01' => 'absent',
            '2026-11-02' => 'weekend',
            '2026-11-03' => 'absent',
            '2026-11-04' => 'absent',
            '2026-11-05' => 'weekend',
        ], $this->statuses($part));
    }

    public function test_coming_in_on_a_day_off_is_not_late(): void
    {
        $user = $this->staff();
        $employee = $this->employeeFor($user);
        $employee->edata_work_days = '0,2,3';
        $employee->save();

        // Monday 2 Nov at 11:00, well after the start time, but Monday is not one of their days.
        Carbon::setTestNow(Carbon::parse('2026-11-02 11:00', 'Africa/Cairo')->utc());
        AttendanceService::for($this->company)->clockIn($employee);
        $state = AttendanceService::for($this->company)->stateFor($employee);

        $this->assertSame(0, $state['late_minutes']);
        $this->assertTrue($state['rules']['part_time']);
        $this->assertSame([0, 2, 3], $state['rules']['work_days']);
    }

    public function test_schedule_is_set_from_the_employee_form(): void
    {
        $person = $this->staff([], 'own', 'Part Timer');
        $employee = $this->employeeFor($person);
        $base = ['name' => 'Part Timer', 'email' => $person->email, 'role' => $person->roles->first()->id, 'employment_status' => 'active'];

        $this->actingAs($this->owner)->put(route('edata.people.update', $person->id), $base + ['work_type' => 'part', 'work_days' => [3, 0, 2]])->assertSessionHasNoErrors();
        $this->assertSame('0,2,3', $employee->fresh()->edata_work_days);
        $this->get(route('edata.people.show', $person->id))->assertOk()->assertSee('دوام جزئي');

        // Part time without any day is refused.
        $this->put(route('edata.people.update', $person->id), $base + ['work_type' => 'part'])->assertSessionHasErrors('work_days');
        $this->assertSame('0,2,3', $employee->fresh()->edata_work_days);

        $this->put(route('edata.people.update', $person->id), $base + ['work_type' => 'full', 'work_days' => [1]])->assertSessionHasNoErrors();
        $this->assertNull($employee->fresh()->edata_work_days);
    }
}
