<?php

namespace Tests\Feature\Edata;

use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FeedbackTest extends EdataTestCase
{
    private const SELF = ['send suggestion', 'view my review'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00', 'Africa/Cairo')->utc());
    }

    public function test_named_suggestion_gets_a_reply_back(): void
    {
        $user = $this->staff(self::SELF);
        $this->employeeFor($user);

        $this->actingAs($user)->post(route('edata.suggestions.store'), ['category' => 'work', 'body' => 'نحتاج قالب موحد للتقارير'])->assertSessionHas('success');
        $row = DB::table('edata_suggestions')->where('company_id', $this->company)->latest('id')->first();
        $this->assertSame((int) $user->id, (int) $row->user_id);
        $this->assertTrue(Notification::where('user_id', $this->owner->id)->where('type', 'suggestion_new')->exists());

        $this->actingAs($this->owner)->get(route('edata.suggestions.index'))->assertOk()->assertSee('نحتاج قالب موحد للتقارير');
        $this->post(route('edata.suggestions.answer', $row->id), ['status' => 'done', 'reply' => 'تم عمل القالب'])->assertSessionHas('success');
        $this->assertSame('done', DB::table('edata_suggestions')->where('id', $row->id)->value('status'));
        $this->assertTrue(Notification::where('user_id', $user->id)->where('type', 'suggestion_answer')->exists());

        $this->actingAs($user)->get(route('edata.me'))->assertOk()->assertSee('تم عمل القالب');
    }

    public function test_anonymous_suggestion_keeps_no_trace(): void
    {
        $user = $this->staff(self::SELF, 'own', 'Hidden Person');
        $this->employeeFor($user);

        $this->actingAs($user)->post(route('edata.suggestions.store'), ['category' => 'place', 'body' => 'التكييف ضعيف في الصالة', 'anonymous' => '1'])->assertSessionHas('success');
        $row = DB::table('edata_suggestions')->where('company_id', $this->company)->latest('id')->first();
        $this->assertNull($row->user_id);

        $this->actingAs($this->owner)->get(route('edata.suggestions.index'))->assertOk()
            ->assertSee('التكييف ضعيف في الصالة')->assertSee('ed-avatar--anon', false)->assertDontSee('Hidden Person');
        // Nothing comes back to the author, and it is not in their own list.
        $this->actingAs($user)->get(route('edata.me'))->assertOk()->assertDontSee('التكييف ضعيف في الصالة');
    }

    public function test_suggestions_follow_permissions(): void
    {
        $user = $this->staff();
        $this->employeeFor($user);

        $this->actingAs($user)->post(route('edata.suggestions.store'), ['category' => 'idea', 'body' => 'فكرة بدون صلاحية'])->assertForbidden();
        $this->get(route('edata.suggestions.index'))->assertForbidden();
        $this->get(route('edata.me'))->assertOk()->assertDontSee(route('edata.suggestions.store'), false);
        $this->assertFalse(DB::table('edata_suggestions')->where('body', 'فكرة بدون صلاحية')->exists());
    }

    public function test_monthly_review_reaches_the_employee(): void
    {
        $user = $this->staff(self::SELF, 'own', 'Reviewed Person');
        $employee = $this->employeeFor($user);

        $this->actingAs($this->owner)->get(route('edata.reviews.index'))->assertOk()->assertSee('Reviewed Person');
        $this->post(route('edata.reviews.save', $employee->id), ['month' => '2026-11', 'rating' => 4, 'strengths' => 'تسليم في الموعد', 'improve' => 'توثيق أكثر'])->assertSessionHas('success');

        $review = DB::table('edata_reviews')->where('employee_id', $employee->id)->first();
        $this->assertSame('2026-11', $review->month);
        $this->assertSame(4, (int) $review->rating);
        $this->assertTrue(Notification::where('user_id', $user->id)->where('type', 'review_new')->exists());

        // Saving again updates the same month instead of adding a second review.
        $this->post(route('edata.reviews.save', $employee->id), ['month' => '2026-11', 'rating' => 5])->assertSessionHas('success');
        $this->assertSame(1, DB::table('edata_reviews')->where('employee_id', $employee->id)->count());

        $this->actingAs($user)->get(route('edata.me'))->assertOk()->assertSee('تقييمك الشهري')->assertSee('ممتاز');
        // A future month is not allowed.
        $this->actingAs($this->owner)->post(route('edata.reviews.save', $employee->id), ['month' => '2027-01', 'rating' => 3]);
        $this->assertFalse(DB::table('edata_reviews')->where('month', '2027-01')->exists());
    }

    public function test_reviews_need_their_permission_and_stay_in_scope(): void
    {
        $other = $this->staff(self::SELF);
        $otherEmployee = $this->employeeFor($other);
        $plain = $this->staff(self::SELF);
        $this->employeeFor($plain);

        $this->actingAs($plain)->get(route('edata.reviews.index'))->assertForbidden();
        $this->post(route('edata.reviews.save', $otherEmployee->id), ['month' => '2026-11', 'rating' => 1])->assertForbidden();

        // A reviewer limited to their own records cannot review someone else.
        $lead = $this->staff(['manage review'], 'own');
        $this->actingAs($lead)->post(route('edata.reviews.save', $otherEmployee->id), ['month' => '2026-11', 'rating' => 2])->assertNotFound();
        $this->assertFalse(DB::table('edata_reviews')->where('employee_id', $otherEmployee->id)->exists());
    }
}
