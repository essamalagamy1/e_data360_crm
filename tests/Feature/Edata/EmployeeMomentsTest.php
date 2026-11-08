<?php

namespace Tests\Feature\Edata;

use App\Models\AttendanceEmployee;
use App\Models\EdataKudos;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use App\Support\Edata\Moments;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeMomentsTest extends EdataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tests read the Arabic wording, whatever the test runner's default language is.
        app()->setLocale('ar');
    }

    /** Thursday 09:00 in Cairo, a working day that comes before the weekend. */
    private function atThursday(string $time = '09:00'): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 ' . $time, 'Africa/Cairo')->utc());
    }

    public function test_clock_in_shows_the_opening_prayer(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $this->employeeFor($user);

        $this->actingAs($user)->post(route('edata.me.clock-in'))
            ->assertRedirect()
            ->assertSessionHas('ed_moment', fn ($m) => $m['kind'] === 'in' && $m['title'] === 'توكلنا على الله' && in_array($m['dua'], config('edata_moments.start_duas'), true));
    }

    public function test_the_moment_appears_once_and_not_after_a_reload(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $this->employeeFor($user);

        $this->actingAs($user)->from(route('edata.me'))->post(route('edata.me.clock-in'));

        $this->get(route('edata.me'))->assertOk()->assertSee('edMoment', false)->assertSee('توكلنا على الله');
        $this->get(route('edata.me'))->assertOk()->assertDontSee('id="edMoment"', false);
    }

    public function test_arriving_late_gets_the_same_warm_words(): void
    {
        $this->atThursday('12:30');
        $user = $this->staff();
        $this->employeeFor($user);

        $response = $this->actingAs($user)->post(route('edata.me.clock-in'));
        $moment = session('ed_moment');

        $response->assertRedirect();
        $this->assertSame('توكلنا على الله', $moment['title']);
        $this->assertStringNotContainsString('متأخر', json_encode($moment, JSON_UNESCAPED_UNICODE));
    }

    public function test_coming_back_after_a_break_says_so(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $this->employeeFor($user);
        $this->actingAs($user)->post(route('edata.me.clock-in'));
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->post(route('edata.me.clock-out'));
        Carbon::setTestNow(Carbon::now()->addHour());

        $this->post(route('edata.me.clock-in'))
            ->assertSessionHas('ed_moment', fn ($m) => $m['title'] === 'نكمل على بركة الله');
    }

    public function test_clock_out_thanks_with_real_time_and_the_next_working_day(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $this->employeeFor($user);
        $this->actingAs($user)->post(route('edata.me.clock-in'));
        Carbon::setTestNow(Carbon::now()->addHours(8));

        $this->post(route('edata.me.clock-out'))->assertSessionHas('ed_moment', function ($m) {
            return $m['kind'] === 'out'
                && $m['facts'][0]['value'] === '8 س'
                && str_contains($m['farewell'], 'الأحد');
        });
    }

    public function test_leaving_early_never_says_see_you_tomorrow_as_if_the_day_ended(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $this->employeeFor($user);
        $this->actingAs($user)->post(route('edata.me.clock-in'));
        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->post(route('edata.me.clock-out'))
            ->assertSessionHas('ed_moment', fn ($m) => $m['farewell'] === 'نراك قريباً بإذن الله.');
    }

    public function test_a_refused_clock_in_shows_no_celebration(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $this->employeeFor($user);
        $this->actingAs($user)->post(route('edata.me.clock-in'));

        $this->post(route('edata.me.clock-in'))
            ->assertSessionMissing('ed_moment')
            ->assertSessionHas('error');
    }

    public function test_the_daily_line_stays_the_same_all_day(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $employee = $this->employeeFor($user);

        $first = Moments::hero($user, $employee, $this->company)['thanks'];
        Carbon::setTestNow(Carbon::now()->addHours(5));

        $this->assertSame($first, Moments::hero($user, $employee, $this->company)['thanks']);
        $this->assertContains($first, config('edata_moments.thanks'));
    }

    public function test_birthday_and_anniversary_are_celebrated_on_the_day(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $employee = $this->employeeFor($user, '2023-03-01');

        $employee->dob = '1995-11-05';
        $this->assertSame('ti-gift',Moments::occasion($employee, Carbon::now('Africa/Cairo'), 'Sara')['icon']);

        $employee->dob = '1995-01-01';
        $employee->company_doj = '2023-11-05';
        $occasion = Moments::occasion($employee, Carbon::now('Africa/Cairo'), 'Sara');
        $this->assertSame('ti-award', $occasion['icon']);
        $this->assertStringContainsString('3 سنوات', $occasion['text']);

        $employee->company_doj = '2026-11-05';
        $this->assertNull(Moments::occasion($employee, Carbon::now('Africa/Cairo'), 'Sara'), 'The joining day itself is not an anniversary.');
    }

    public function test_time_with_the_company_reads_correctly_in_arabic(): void
    {
        $to = Carbon::parse('2026-11-05');

        $this->assertSame('سنة', Moments::span(Carbon::parse('2025-11-05'), $to));
        $this->assertSame('سنتين', Moments::span(Carbon::parse('2024-11-05'), $to));
        $this->assertSame('3 سنوات', Moments::span(Carbon::parse('2023-11-05'), $to));
        $this->assertSame('11 سنة', Moments::span(Carbon::parse('2015-11-05'), $to));
        $this->assertSame('سنة وشهرين', Moments::span(Carbon::parse('2025-09-05'), $to));
        $this->assertSame('5 أيام', Moments::span(Carbon::parse('2026-10-31'), $to));
    }

    public function test_the_streak_skips_days_off_and_stops_at_a_late_day(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $employee = $this->employeeFor($user);

        foreach (['2026-11-04' => '08:55:00', '2026-11-03' => '09:00:00', '2026-11-02' => '09:05:00', '2026-11-01' => '11:00:00'] as $date => $in) {
            $row = new AttendanceEmployee();
            $row->forceFill([
                'employee_id' => $employee->id, 'date' => $date, 'status' => 'Present', 'clock_in' => $in, 'clock_out' => '17:00:00',
                'late' => '00:00:00', 'early_leaving' => '00:00:00', 'overtime' => '00:00:00', 'total_rest' => '00:00:00', 'created_by' => $this->company,
            ])->save();
        }

        // Three on-time days, then Sunday 1 November was two hours late.
        $this->assertSame(3, Moments::onTimeStreak($employee, $this->company));
    }

    public function test_the_employee_page_renders_with_and_without_an_employee_record(): void
    {
        $this->atThursday();
        $user = $this->staff();
        $this->employeeFor($user);

        $this->actingAs($user)->get(route('edata.me'))->assertOk()
            ->assertSee('ed-day', false)
            ->assertSee('رحلتك معنا')
            ->assertSee('كلمة شكر');

        $this->actingAs($this->owner)->get(route('edata.me'))->assertOk()
            ->assertSee('ed-day', false)
            ->assertDontSee('data-clock', false);
    }

    public function test_a_thank_you_note_reaches_the_colleague(): void
    {
        $from = $this->staff([], 'own', 'Sender Name');
        $to = $this->staff();
        $this->employeeFor($from);
        $this->employeeFor($to);

        $this->actingAs($from)->post(route('edata.kudos.store'), ['to_user_id' => $to->id, 'message' => 'شكراً على مساعدتك'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertTrue(EdataKudos::where('from_user_id', $from->id)->where('to_user_id', $to->id)->exists());
        $this->assertStringContainsString('Sender Name', Notification::where('user_id', $to->id)->where('type', 'kudos')->value('data'));

        $this->actingAs($to)->get(route('edata.me'))->assertOk()->assertSee('شكراً على مساعدتك');
    }

    public function test_a_note_cannot_go_to_yourself_or_outside_the_company(): void
    {
        $me = $this->staff();
        $this->employeeFor($me);

        $outsider = User::where('id', '!=', $this->company)->where('created_by', '!=', $this->company)->first();
        if (!$outsider) {
            $outsider = new User();
            $outsider->forceFill(['name' => 'Outside', 'email' => 'outside' . uniqid() . '@test.local', 'password' => 'x', 'type' => 'company', 'lang' => 'ar', 'created_by' => 1])->save();
        }

        $this->actingAs($me)->post(route('edata.kudos.store'), ['to_user_id' => $me->id, 'message' => 'شكراً لنفسي'])->assertSessionHasErrors('to_user_id');
        $this->actingAs($me)->post(route('edata.kudos.store'), ['to_user_id' => $outsider->id, 'message' => 'شكراً لك'])->assertSessionHasErrors('to_user_id');
        $this->assertSame(0, EdataKudos::where('from_user_id', $me->id)->count());
    }

    public function test_a_note_is_escaped_when_shown(): void
    {
        $from = $this->staff();
        $to = $this->staff();
        $this->employeeFor($from);
        $this->employeeFor($to);
        EdataKudos::create(['company_id' => $this->company, 'from_user_id' => $from->id, 'to_user_id' => $to->id, 'message' => '<script>alert(1)</script>']);

        $this->actingAs($to)->get(route('edata.me'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_notes_have_a_daily_limit(): void
    {
        config(['edata_moments.kudos_daily_limit' => 2]);
        $from = $this->staff();
        $to = $this->staff();
        $this->employeeFor($from);
        $this->employeeFor($to);

        foreach ([1, 2, 3] as $n) {
            $this->actingAs($from)->post(route('edata.kudos.store'), ['to_user_id' => $to->id, 'message' => 'شكراً ' . $n]);
        }

        $this->assertSame(2, EdataKudos::where('from_user_id', $from->id)->count());
    }

    public function test_only_the_writer_or_the_owner_can_remove_a_note(): void
    {
        $from = $this->staff();
        $to = $this->staff();
        $note = EdataKudos::create(['company_id' => $this->company, 'from_user_id' => $from->id, 'to_user_id' => $to->id, 'message' => 'شكراً']);

        $this->actingAs($to)->delete(route('edata.kudos.destroy', $note->id))->assertForbidden();
        $this->actingAs($from)->delete(route('edata.kudos.destroy', $note->id))->assertRedirect();
        $this->assertNull(EdataKudos::find($note->id));

        $second = EdataKudos::create(['company_id' => $this->company, 'from_user_id' => $from->id, 'to_user_id' => $to->id, 'message' => 'شكراً']);
        $this->actingAs($this->owner)->delete(route('edata.kudos.destroy', $second->id))->assertRedirect();
        $this->assertNull(EdataKudos::find($second->id));
    }

    public function test_finishing_a_task_tells_the_screen_to_celebrate(): void
    {
        $user = $this->staff();
        $project = $this->project([], [$user]);
        $stages = TaskRules::stages($this->company);
        if ($stages->count() < 2) {
            foreach (['To Do', 'Done'] as $order => $name) {
                DB::table('task_stages')->insert(['name' => $name, 'color' => '#000', 'order' => $order, 'created_by' => $this->company, 'created_at' => now(), 'updated_at' => now()]);
            }
            TaskRules::flush();
            $stages = TaskRules::stages($this->company);
        }
        $task = new \App\Models\ProjectTask();
        $task->forceFill(['name' => 'T', 'project_id' => $project->id, 'stage_id' => $stages->first()->id, 'assign_to' => (string) $user->id, 'priority' => 'medium', 'created_by' => $this->company, 'is_complete' => 0])->save();

        $this->actingAs($user)->postJson(route('edata.tasks.move', $task->id), ['stage_id' => $stages->last()->id])
            ->assertOk()->assertJson(['done' => true]);
        $this->actingAs($user)->postJson(route('edata.tasks.move', $task->id), ['stage_id' => $stages->first()->id])
            ->assertOk()->assertJson(['done' => false]);
    }
}
