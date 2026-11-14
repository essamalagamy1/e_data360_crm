<?php

namespace Tests\Feature\Edata;

use App\Models\EdataAuditLog;
use App\Models\Leave;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveRulesTest extends EdataTestCase
{
    private const SELF = ['create self request'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00', 'Africa/Cairo')->utc());
    }

    private function type(): int
    {
        return (int) DB::table('leave_types')->where('created_by', $this->company)->value('id');
    }

    private function ask(User $user, string $from, string $to, array $extra = [])
    {
        return $this->actingAs($user)->post(route('edata.my.leave'), ['leave_type_id' => $this->type(), 'start_date' => $from, 'end_date' => $to, 'reason' => 'ظرف عائلي'] + $extra);
    }

    public function test_owner_can_delete_leave_and_requests(): void
    {
        $user = $this->staff(self::SELF);
        $employee = $this->employeeFor($user);
        $this->ask($user, '2026-11-10', '2026-11-11')->assertSessionHas('success');
        $this->post(route('edata.my.permission'), ['date' => '2026-11-05', 'time_from' => '13:00', 'time_to' => '14:00', 'reason' => 'موعد']);
        $leave = Leave::where('employee_id', $employee->id)->firstOrFail();
        $requestId = DB::table('edata_requests')->where('employee_id', $employee->id)->value('id');

        $this->actingAs($this->owner)->delete(route('edata.requests.destroy', ['leave', $leave->id]))->assertSessionHas('success');
        $this->delete(route('edata.requests.destroy', ['request', $requestId]))->assertSessionHas('success');

        $this->assertNull(Leave::find($leave->id));
        $this->assertFalse(DB::table('edata_requests')->where('id', $requestId)->exists());
        $this->assertTrue(EdataAuditLog::where('action', 'deleted')->where('entity_id', $leave->id)->exists());

        // An employee cannot delete requests.
        $this->ask($user, '2026-11-12', '2026-11-12');
        $again = Leave::where('employee_id', $employee->id)->firstOrFail();
        $this->actingAs($user)->delete(route('edata.requests.destroy', ['leave', $again->id]))->assertForbidden();
        $this->assertNotNull(Leave::find($again->id));
    }

    public function test_terms_must_be_accepted(): void
    {
        $user = $this->staff(self::SELF);
        $this->employeeFor($user);

        $this->actingAs($this->owner)->post(route('edata.requests.terms'), ['terms' => 'تقديم الطلب قبل 3 أيام على الأقل.'])->assertSessionHas('success');
        \App\Support\Edata\Fmt::flush();

        $this->actingAs($user)->get(route('edata.me'))->assertOk()->assertSee('تقديم الطلب قبل 3 أيام على الأقل.')->assertSee('accept_terms', false);
        $this->ask($user, '2026-11-10', '2026-11-10')->assertSessionHasErrors('accept_terms');
        $this->assertSame(0, Leave::where('created_by', $this->company)->where('leave_reason', 'ظرف عائلي')->count());
        $this->ask($user, '2026-11-10', '2026-11-10', ['accept_terms' => '1'])->assertSessionHas('success');

        // Clearing the terms removes the step.
        $this->actingAs($this->owner)->post(route('edata.requests.terms'), ['terms' => ''])->assertSessionHas('success');
        \App\Support\Edata\Fmt::flush();
        $this->ask($user, '2026-11-12', '2026-11-12')->assertSessionHas('success');
    }

    public function test_each_employee_has_their_own_allowance(): void
    {
        $user = $this->staff(self::SELF, 'own', 'Leave Person');
        $employee = $this->employeeFor($user);
        $base = ['name' => 'Leave Person', 'email' => $user->email, 'role' => $user->roles->first()->id, 'employment_status' => 'active'];

        $this->actingAs($this->owner)->put(route('edata.people.update', $user->id), $base + ['allowance' => [$this->type() => '5']])->assertSessionHasNoErrors();
        $this->assertEquals(5, DB::table('edata_leave_allowances')->where('employee_id', $employee->id)->value('days'));

        // More than the allowance is refused, within it goes through.
        $this->ask($user, '2026-11-10', '2026-11-15')->assertSessionHasErrors('start_date');
        $this->ask($user, '2026-11-10', '2026-11-12')->assertSessionHas('success');
        // Three days wait for a reply, so only two are free now.
        $this->ask($user, '2026-11-20', '2026-11-22')->assertSessionHasErrors('start_date');

        $leave = Leave::where('employee_id', $employee->id)->firstOrFail();
        $this->actingAs($this->owner)->post(route('edata.requests.decide', ['leave', $leave->id]), ['decision' => 'approve'])->assertSessionHas('success');

        $balance = \App\Support\Edata\EmployeeServices::leaveBalances($employee, $this->company, Carbon::now())->firstWhere('id', $this->type());
        $this->assertEquals(5, $balance->days);
        $this->assertEquals(3, $balance->used);
        $this->assertEquals(2, $balance->left);
        $this->get(route('edata.people.show', $user->id))->assertOk()->assertSee('ed-leavebal', false);

        // An empty box goes back to the type's default.
        $this->put(route('edata.people.update', $user->id), $base + ['allowance' => [$this->type() => '']])->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('edata_leave_allowances')->where('employee_id', $employee->id)->exists());
    }
}
