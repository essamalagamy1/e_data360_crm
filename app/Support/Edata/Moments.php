<?php

namespace App\Support\Edata;

use App\Models\AttendanceEmployee;
use App\Models\EdataKudos;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\ProjectUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The human side of the employee's day: greetings, the words at clock-in and
 * clock-out, milestones and occasions. Numbers shown here always come from real
 * records, and nothing here ever comments on being late or leaving early.
 */
class Moments
{
    public static function firstName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];

        return $parts[0] ?? '';
    }

    /** One line per person per day: stable across reloads, varied across people. */
    private static function pick(array $lines, string $salt, int $person, Carbon $day): string
    {
        if (!$lines) {
            return '';
        }

        return $lines[abs(crc32($salt . '|' . $person . '|' . $day->toDateString())) % count($lines)];
    }

    public static function phase(Carbon $now): string
    {
        $hour = (int) $now->format('G');

        return match (true) {
            $hour >= 4 && $hour < 7 => 'dawn',
            $hour >= 7 && $hour < 11 => 'morning',
            $hour >= 11 && $hour < 15 => 'noon',
            $hour >= 15 && $hour < 18 => 'afternoon',
            $hour >= 18 && $hour < 20 => 'evening',
            default => 'night',
        };
    }

    private static function greetingWord(Carbon $now): string
    {
        if ($now->dayOfWeek === Carbon::FRIDAY) {
            return 'جمعة مباركة';
        }
        $hour = (int) $now->format('G');

        return $hour >= 4 && $hour < 12 ? 'صباح الخير' : 'مساء الخير';
    }

    /** What the top of the employee's page says. */
    public static function hero(User $me, ?Employee $employee, int $company): array
    {
        $now = Carbon::now(Fmt::timezone($company));
        $person = (int) ($employee->id ?? $me->id);
        $first = self::firstName($employee->name ?? $me->name);

        return [
            'first' => $first,
            'greeting' => __(':greeting يا :name', ['greeting' => __(self::greetingWord($now)), 'name' => $first]),
            'thanks' => __(self::pick((array) config('edata_moments.thanks'), 'thanks', $person, $now)),
            'phase' => self::phase($now),
            'hijri' => self::hijri($now),
            'occasion' => self::occasion($employee, $now, $first),
        ];
    }

    /** The thank-you line on the login page, where nobody is known yet. */
    public static function loginLine(): string
    {
        return __(self::pick((array) config('edata_moments.thanks'), 'login', 0, Carbon::now()));
    }

    /** The Hijri date, when the server has the intl extension. Otherwise nothing is shown. */
    public static function hijri(Carbon $day): ?string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return null;
        }

        try {
            $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
            $formatter = new \IntlDateFormatter($locale . '@calendar=islamic-umalqura;numbers=latn', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $day->getTimezone()->getName(), \IntlDateFormatter::TRADITIONAL, 'd MMMM y');
            $text = $formatter->format($day->getTimestamp());

            return $text === false ? null : $text;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** [month, day] in the Hijri calendar, or null when intl is missing. */
    private static function hijriParts(Carbon $day): ?array
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return null;
        }

        try {
            $formatter = new \IntlDateFormatter('en@calendar=islamic-umalqura;numbers=latn', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $day->getTimezone()->getName(), \IntlDateFormatter::TRADITIONAL, 'M/d');
            $text = $formatter->format($day->getTimestamp());
            if ($text === false || !str_contains($text, '/')) {
                return null;
            }

            return array_map('intval', explode('/', $text));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A personal or seasonal occasion today, if there is one. Personal ones win.
     *
     * @return array{icon:string,text:string,celebrate:bool}|null
     */
    public static function occasion(?Employee $employee, Carbon $today, string $first): ?array
    {
        if ($employee) {
            $birthday = self::safeDate($employee->dob ?? null);
            if ($birthday && $birthday->format('m-d') === $today->format('m-d')) {
                return ['icon' => 'ti-gift', 'text' => __('كل عام وأنت بخير يا :name. سعداء بوجودك معنا.', ['name' => $first]), 'celebrate' => true];
            }

            $joined = self::safeDate($employee->company_doj ?? null);
            if ($joined && $joined->format('m-d') === $today->format('m-d') && $joined->year < $today->year) {
                return ['icon' => 'ti-award', 'text' => __('اليوم تكمل :span معنا. شكراً على كل يوم منها.', ['span' => self::span($joined, $today)]), 'celebrate' => true];
            }
        }

        $hijri = self::hijriParts($today);
        if ($hijri) {
            [$month, $day] = $hijri;
            if ($month === 9) {
                return ['icon' => 'ti-moon-stars', 'text' => __('رمضان كريم. تقبل الله منا ومنكم.'), 'celebrate' => false];
            }
            if ($month === 10 && $day <= 3) {
                return ['icon' => 'ti-confetti', 'text' => __('عيد فطر مبارك. كل عام وأنتم بخير.'), 'celebrate' => true];
            }
            if ($month === 12 && $day >= 10 && $day <= 13) {
                return ['icon' => 'ti-confetti', 'text' => __('عيد أضحى مبارك. كل عام وأنتم بخير.'), 'celebrate' => true];
            }
        }

        return null;
    }

    /** What the screen says after clock-in. */
    public static function clockIn(Employee $employee, AttendanceEmployee $row, array $state, int $openTasks, ?string $nearestTask, int $company): array
    {
        $rules = $state['rules'];
        $now = Carbon::now($rules['zone']);
        $first = self::firstName($employee->name);
        $returning = $state['finished_seconds'] > 0;
        $offDay = !in_array($now->dayOfWeek, $rules['work_days'], true);

        $facts = [['label' => __('وقت الحضور'), 'value' => substr((string) $row->clock_in, 0, 5)]];
        if ($openTasks > 0) {
            $facts[] = ['label' => __('مهامك المفتوحة'), 'value' => (string) $openTasks];
        }

        return [
            'kind' => 'in',
            'title' => $returning ? 'نكمل على بركة الله' : 'توكلنا على الله',
            'dua' => self::pick((array) config('edata_moments.start_duas'), 'start', (int) $employee->id, $now),
            'lead' => $offDay
                ? __('شكراً لأنك معنا حتى في يوم راحتك يا :name.', ['name' => $first])
                : ($returning ? __('أهلاً بعودتك يا :name.', ['name' => $first]) : __('يوم موفق يا :name.', ['name' => $first])),
            'facts' => $facts,
            'focus' => $nearestTask ? __('أقرب مهمة لك: :task', ['task' => $nearestTask]) : null,
            'note' => null,
            'farewell' => null,
            'button' => $returning ? __('بسم الله، نكمل') : __('بسم الله، نبدأ'),
            'seconds' => 10,
        ];
    }

    /** What the screen says after clock-out. */
    public static function clockOut(Employee $employee, array $state, int $doneToday, int $company): array
    {
        $rules = $state['rules'];
        $now = Carbon::now($rules['zone']);
        $first = self::firstName($employee->name);
        $total = (int) $state['total_seconds'];
        $overtime = max(0, $total - (int) $rules['required_seconds']);
        $shiftEnd = Carbon::parse($now->toDateString() . ' ' . $rules['end'], $rules['zone']);

        $facts = [['label' => __('وقت عملك اليوم'), 'value' => Labels::hours($total)]];
        if ($doneToday > 0) {
            $facts[] = ['label' => __('مهام أنجزتها اليوم'), 'value' => (string) $doneToday];
        }
        if ($overtime >= 300) {
            $facts[] = ['label' => __('وقت إضافي'), 'value' => Labels::hours($overtime)];
        }

        return [
            'kind' => 'out',
            'title' => __('شكراً يا :name', ['name' => $first]),
            'lead' => __('شكراً على وقتك ومجهودك اليوم.'),
            'dua' => self::pick((array) config('edata_moments.end_duas'), 'end', (int) $employee->id, $now),
            'facts' => $facts,
            'focus' => null,
            'note' => $overtime >= 300 ? __('وقتك الإضافي اليوم محل تقدير كبير.') : null,
            'farewell' => $now->lessThan($shiftEnd->copy()->subHour()) ? __('نراك قريباً بإذن الله.') : self::farewell($now, $rules, $company),
            'button' => __('في أمان الله'),
            'seconds' => 11,
        ];
    }

    private static function farewell(Carbon $now, array $rules, int $company): string
    {
        $next = $now->copy()->startOfDay();
        $holidays = Holiday::where('created_by', $company)
            ->where('date', '<=', $now->copy()->addDays(10)->toDateString())
            ->where(fn ($q) => $q->where('end_date', '>=', $now->toDateString())->orWhereNull('end_date'))
            ->get();

        for ($i = 1; $i <= 10; $i++) {
            $next = $now->copy()->startOfDay()->addDays($i);
            $date = $next->toDateString();
            $isHoliday = $holidays->contains(function ($holiday) use ($date) {
                $start = Carbon::parse($holiday->date)->toDateString();
                $end = $holiday->end_date ? Carbon::parse($holiday->end_date)->toDateString() : $start;

                return $date >= $start && $date <= $end;
            });
            if (!$isHoliday && in_array($next->dayOfWeek, $rules['work_days'], true)) {
                break;
            }
        }

        if ($next->isSameDay($now->copy()->addDay())) {
            return __('نراك غداً بإذن الله.');
        }

        return __('إجازة سعيدة، ونراك يوم :day بإذن الله.', ['day' => $next->copy()->locale(app()->getLocale())->translatedFormat('l')]);
    }

    /** "6 سنوات و10 أشهر", from the joining date up to a given day. */
    public static function span(Carbon $from, Carbon $to): string
    {
        $years = (int) $from->diffInYears($to, true);
        $months = (int) $from->copy()->addYears($years)->diffInMonths($to, true);
        $days = (int) $from->diffInDays($to, true);

        if (app()->getLocale() === 'en') {
            $parts = [];
            if ($years) {
                $parts[] = $years . ' ' . ($years === 1 ? 'year' : 'years');
            }
            if ($months) {
                $parts[] = $months . ' ' . ($months === 1 ? 'month' : 'months');
            }

            return $parts ? implode(', ', $parts) : $days . ' ' . ($days === 1 ? 'day' : 'days');
        }

        $parts = [];
        if ($years) {
            $parts[] = self::arabicCount($years, 'سنة', 'سنتين', 'سنوات');
        }
        if ($months) {
            $parts[] = self::arabicCount($months, 'شهر', 'شهرين', 'أشهر');
        }

        return $parts ? implode(' و', $parts) : self::arabicCount(max(1, $days), 'يوم', 'يومين', 'أيام');
    }

    private static function arabicCount(int $n, string $one, string $two, string $few): string
    {
        return match (true) {
            $n === 1 => $one,
            $n === 2 => $two,
            $n >= 3 && $n <= 10 => $n . ' ' . $few,
            default => $n . ' ' . $one,
        };
    }

    /** The "your journey with us" figures. Only real records, nothing estimated. */
    public static function journey(User $me, ?Employee $employee, int $tasksDone, int $company): array
    {
        $now = Carbon::now(Fmt::timezone($company));
        $since = self::safeDate($employee->company_doj ?? null) ?? self::safeDate($employee->created_at ?? $me->created_at);

        $milestones = (array) config('edata_moments.task_milestones');
        $previous = 0;
        $next = null;
        foreach ($milestones as $milestone) {
            if ($tasksDone < $milestone) {
                $next = $milestone;
                break;
            }
            $previous = $milestone;
        }

        return [
            'since' => $since,
            'span' => $since && $since->lessThanOrEqualTo($now) ? self::span($since, $now) : null,
            'tasks' => $tasksDone,
            'projects' => ProjectUser::where('user_id', $me->id)->count(),
            'hours' => $employee ? (int) floor((int) AttendanceEmployee::where('employee_id', $employee->id)->sum('worked_seconds') / 3600) : 0,
            'milestone_next' => $next,
            'milestone_left' => $next ? $next - $tasksDone : 0,
            'milestone_percent' => $next ? (int) round(($tasksDone - $previous) / max(1, $next - $previous) * 100) : 100,
        ];
    }

    /** Consecutive working days on time, counted back from today. Days off do not break it. */
    public static function onTimeStreak(Employee $employee, int $company): int
    {
        $service = AttendanceService::for($company);
        $today = Carbon::now($service->rules()['zone']);
        $days = $service->report(collect([$employee]), $today->copy()->subDays(60)->toDateString(), $today->toDateString());

        $streak = 0;
        foreach ($days as $day) {
            if (in_array($day['status'], ['weekend', 'holiday', 'leave', 'not_yet'], true)) {
                continue;
            }
            if (in_array('late', $day['flags'], true) || !in_array($day['status'], ['present', 'overtime', 'working'], true)) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    /**
     * Who a note can be sent to: colleagues with an active employee record, plus the
     * company owner. Only names leave the database here, so a role limited to its own
     * records still cannot read anyone else's data through this list.
     */
    public static function teammates(int $company, int $exceptUserId): Collection
    {
        $staff = Employee::where('created_by', $company)
            ->whereNotNull('user_id')
            ->where('employment_status', '!=', 'ended')
            ->pluck('user_id')
            ->push($company);

        return User::whereIn('id', $staff)
            ->where('id', '!=', $exceptUserId)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public static function recentKudos(int $company, int $limit = 6): Collection
    {
        return EdataKudos::where('company_id', $company)
            ->with(['sender:id,name,avatar', 'recipient:id,name,avatar'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private static function safeDate($value): ?Carbon
    {
        if (empty($value) || $value === '0000-00-00') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
