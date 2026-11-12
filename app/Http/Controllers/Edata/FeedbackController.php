<?php

namespace App\Http\Controllers\Edata;

use App\Models\Employee;
use App\Models\User;
use App\Support\Edata\Access;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\EmployeeServices;
use App\Support\Edata\Labels;
use App\Support\Edata\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The suggestion box and the simple monthly review.
 */
class FeedbackController extends BaseController
{
    public const CATEGORIES = [
        'work' => 'تحسين طريقة العمل',
        'place' => 'بيئة العمل',
        'idea' => 'فكرة جديدة',
        'other' => 'أخرى',
    ];

    public const SUGGESTION_STATUSES = [
        'new' => ['جديد', 'info'],
        'seen' => ['تمت قراءته', 'muted'],
        'done' => ['تم التنفيذ', 'good'],
    ];

    public const RATINGS = [1 => 'يحتاج إلى دعم', 2 => 'مقبول', 3 => 'جيد', 4 => 'جيد جداً', 5 => 'ممتاز'];

    // ---------------------------------------------------------------- suggestions

    public function storeSuggestion(Request $request)
    {
        $me = $this->me();
        abort_unless(EmployeeServices::allows($me, 'suggest'), 403);
        $company = $this->companyId();

        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'body' => 'required|string|min:5|max:2000',
            'anonymous' => 'nullable|boolean',
        ], [], ['category' => 'نوع الاقتراح', 'body' => 'الاقتراح']);

        $anonymous = !empty($data['anonymous']);
        DB::table('edata_suggestions')->insert([
            'company_id' => $company,
            'user_id' => $anonymous ? null : $me->id,
            'category' => $data['category'],
            'body' => $data['body'],
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $title = $anonymous ? 'اقتراح جديد بدون اسم' : 'اقتراح جديد من ' . $me->name;
        Notifier::send(Notifier::holders($company, 'manage suggestion'), 'suggestion_new', $title, self::CATEGORIES[$data['category']], route('edata.suggestions.index', [], false));

        return $this->done($anonymous ? 'وصل اقتراحك بدون اسمك. شكراً لك.' : 'وصل اقتراحك، وسيصلك إشعار عند الرد. شكراً لك.');
    }

    public function suggestions(Request $request)
    {
        $me = $this->me();
        abort_unless(EmployeeServices::allows($me, 'suggestions'), 403);
        $company = $this->companyId();

        $status = $request->query('status');
        $query = DB::table('edata_suggestions')->where('company_id', $company);
        if (isset(self::SUGGESTION_STATUSES[$status])) {
            $query->where('status', $status);
        }
        $rows = $query->orderByRaw("FIELD(status, 'new', 'seen', 'done')")->orderByDesc('id')->paginate($this->perPage())->withQueryString();
        $counts = DB::table('edata_suggestions')->where('company_id', $company)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $people = User::whereIn('id', collect($rows->items())->pluck('user_id')->merge(collect($rows->items())->pluck('replied_by'))->filter()->unique())->get(['id', 'name', 'avatar'])->keyBy('id');

        return view('edata.suggestions.index', [
            'rows' => $rows,
            'counts' => $counts,
            'people' => $people,
            'status' => $status,
            'categories' => self::CATEGORIES,
            'statuses' => self::SUGGESTION_STATUSES,
        ]);
    }

    public function answerSuggestion(Request $request, int $suggestion)
    {
        $me = $this->me();
        abort_unless(EmployeeServices::allows($me, 'suggestions'), 403);
        $row = DB::table('edata_suggestions')->where('company_id', $this->companyId())->where('id', $suggestion)->first();
        abort_unless($row, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(self::SUGGESTION_STATUSES))],
            'reply' => 'nullable|string|max:2000',
        ], [], ['status' => 'الحالة', 'reply' => 'الرد']);

        $reply = trim((string) ($data['reply'] ?? ''));
        $update = ['status' => $data['status'], 'updated_at' => now()];
        if ($reply !== '' && $reply !== (string) $row->reply) {
            $update += ['reply' => $reply, 'replied_by' => $me->id, 'replied_at' => now()];
        }
        DB::table('edata_suggestions')->where('id', $row->id)->update($update);

        if ($row->user_id && (isset($update['reply']) || $data['status'] !== $row->status)) {
            $title = $data['status'] === 'done' ? 'تم تنفيذ اقتراحك. شكراً لك' : 'وصل رد على اقتراحك';
            Notifier::send((int) $row->user_id, 'suggestion_answer', $title, $update['reply'] ?? null, route('edata.me', [], false) . '#suggest');
        }

        return $this->done($row->user_id ? 'تم الحفظ، ووصل إشعار لصاحب الاقتراح.' : 'تم الحفظ.');
    }

    // ---------------------------------------------------------------- monthly review

    private function reviewMonth(Request $request, Carbon $now): Carbon
    {
        $m = (string) $request->query('m', $request->input('month'));
        $month = preg_match('/^\d{4}-\d{2}$/', $m) ? Carbon::parse($m . '-01', $now->timezone) : $now->copy()->startOfMonth();

        return $month->greaterThan($now) ? $now->copy()->startOfMonth() : $month;
    }

    public function reviews(Request $request)
    {
        $me = $this->me();
        abort_unless(EmployeeServices::allows($me, 'reviews'), 403);
        $company = $this->companyId();
        $now = Carbon::now(AttendanceService::for($company)->rules()['zone']);
        $month = $this->reviewMonth($request, $now);
        $key = $month->format('Y-m');

        $employees = Access::employees($me)
            ->whereNotNull('employees.user_id')
            ->where('employees.employment_status', '!=', 'ended')
            ->where('employees.user_id', '!=', $me->id)
            ->with('user:id,name,avatar,type')
            ->orderBy('employees.name')
            ->get()
            ->filter(fn ($e) => $e->user);

        $reviews = DB::table('edata_reviews')->where('company_id', $company)->where('month', $key)
            ->whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id');
        $summaries = $employees->mapWithKeys(fn ($e) => [$e->id => EmployeeServices::monthSummary($e->user, $e, $company, $month)]);

        return view('edata.reviews.index', [
            'employees' => $employees,
            'reviews' => $reviews,
            'summaries' => $summaries,
            'month' => $month,
            'isThisMonth' => $month->isSameMonth($now),
            'ratings' => self::RATINGS,
        ]);
    }

    public function saveReview(Request $request, int $employee)
    {
        $me = $this->me();
        abort_unless(EmployeeServices::allows($me, 'reviews'), 403);
        $company = $this->companyId();
        $record = Access::employees($me)->whereKey($employee)->whereNotNull('employees.user_id')->firstOrFail();
        abort_if((int) $record->user_id === (int) $me->id, 403);

        $now = Carbon::now(AttendanceService::for($company)->rules()['zone']);
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'rating' => 'required|integer|between:1,5',
            'strengths' => 'nullable|string|max:1500',
            'improve' => 'nullable|string|max:1500',
        ], ['rating.required' => 'اختر التقييم من 1 إلى 5.'], ['rating' => 'التقييم', 'strengths' => 'نقاط القوة', 'improve' => 'ما يحتاج إلى تحسين']);
        $month = $this->reviewMonth($request, $now)->format('Y-m');

        $exists = DB::table('edata_reviews')->where('employee_id', $record->id)->where('month', $month)->exists();
        DB::table('edata_reviews')->updateOrInsert(
            ['employee_id' => $record->id, 'month' => $month],
            [
                'company_id' => $company, 'user_id' => $record->user_id, 'rating' => (int) $data['rating'],
                'strengths' => $data['strengths'] ?? null, 'improve' => $data['improve'] ?? null,
                'reviewer_id' => $me->id, 'updated_at' => now(), 'created_at' => now(),
            ]
        );

        if (!$exists) {
            $label = Labels::date($month . '-01', 'F Y');
            Notifier::send((int) $record->user_id, 'review_new', 'وصلك تقييم شهر ' . $label, self::RATINGS[(int) $data['rating']], route('edata.me', [], false) . '#review');
        }

        return $this->done($exists ? 'تم تحديث تقييم ' . $record->name . '.' : 'تم حفظ تقييم ' . $record->name . '، ووصله إشعار.');
    }
}
