<?php

namespace App\Support\Edata;

use App\Models\AttendanceEmployee;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Utility;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All attendance rules live here.
 *
 * Exact moments are stored in UTC (clock_in_at, clock_out_at). The older date and
 * time columns are still written in the company's timezone, because payroll and
 * the monthly report read them. Late and overtime are always recalculated from the
 * exact times, so a wrong value in an old row can never reach a screen.
 */
class AttendanceService
{
    public function __construct(public int $companyId)
    {
    }

    public static function for(int $companyId): self
    {
        return new self($companyId);
    }

    public function rules(): array
    {
        $settings = Fmt::settings($this->companyId);
        $start = $settings['company_start_time'] ?? '09:00';
        $end = $settings['company_end_time'] ?? '17:00';
        $workDays = isset($settings['edata_work_days']) && $settings['edata_work_days'] !== ''
            ? array_map('intval', explode(',', $settings['edata_work_days']))
            : config('edata.attendance.work_days');

        return [
            'start' => $start,
            'end' => $end,
            'grace' => (int) ($settings['edata_grace_minutes'] ?? config('edata.attendance.grace_minutes')),
            'work_days' => $workDays,
            'zone' => Fmt::timezone($this->companyId),
            'required_seconds' => (int) max(0, Carbon::parse($end)->diffInSeconds(Carbon::parse($start), true)),
            'ip_restrict' => ($settings['ip_restrict'] ?? 'off') === 'on',
        ];
    }

    private function nowUtc(): Carbon
    {
        return Carbon::now('UTC');
    }

    private function nowLocal(): Carbon
    {
        return Carbon::now($this->rules()['zone']);
    }

    private function isStale(AttendanceEmployee $row): bool
    {
        $in = $this->moment($row, 'in');
        if (!$in) {
            return true;
        }

        return $in->diffInHours($this->nowUtc(), true) >= (int) config('edata.attendance.stale_after_hours');
    }

    /** A row's exact moment in UTC, falling back to the stored date and time for old rows. */
    public function moment(AttendanceEmployee $row, string $which): ?Carbon
    {
        $exact = $which === 'in' ? $row->clock_in_at : $row->clock_out_at;
        if ($exact) {
            return Carbon::parse($exact, 'UTC');
        }

        $time = $which === 'in' ? $row->clock_in : $row->clock_out;
        if (empty($time) || $time === '00:00:00') {
            return null;
        }

        $date = Carbon::parse($row->date)->toDateString();

        return Carbon::parse($date . ' ' . $time, $this->rules()['zone'])->utc();
    }

    /** The session currently running for this employee, if any. */
    public function openSession(Employee $employee): ?AttendanceEmployee
    {
        $candidates = AttendanceEmployee::where('employee_id', $employee->id)
            ->where('clock_in', '!=', '00:00:00')
            ->where('clock_out', '00:00:00')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($candidates as $row) {
            if (!$this->isStale($row)) {
                return $row;
            }
        }

        return null;
    }

    public function clockIn(Employee $employee, ?string $ip = null): AttendanceEmployee
    {
        $rules = $this->rules();
        $this->guardIp($rules, $ip);

        try {
            return DB::transaction(function () use ($employee, $rules) {
                Employee::whereKey($employee->id)->lockForUpdate()->first();

                if ($this->openSession($employee)) {
                    throw new DomainException('سجلت حضورك بالفعل. سجّل الانصراف أولاً قبل بدء فترة جديدة.');
                }

                // A forgotten session stays on record as "did not clock out" but stops blocking today.
                AttendanceEmployee::where('employee_id', $employee->id)
                    ->where('open_key', $employee->id)
                    ->update(['open_key' => null]);

                $nowUtc = $this->nowUtc();
                $local = $nowUtc->copy()->setTimezone($rules['zone']);
                $date = $local->toDateString();

                $row = new AttendanceEmployee();
                $row->employee_id = $employee->id;
                $row->date = $date;
                $row->status = 'Present';
                $row->clock_in = $local->format('H:i:s');
                $row->clock_out = '00:00:00';
                $row->clock_in_at = $nowUtc->format('Y-m-d H:i:s');
                $row->open_key = $employee->id;
                $row->source = 'web';
                $row->late = $this->lateClock($date, $local, $rules);
                $row->early_leaving = '00:00:00';
                $row->overtime = '00:00:00';
                $row->total_rest = '00:00:00';
                $row->created_by = $this->companyId;
                $row->save();

                return $row;
            });
        } catch (QueryException $e) {
            // The unique index caught a double submit that slipped past the lock.
            throw new DomainException('سجلت حضورك بالفعل. سجّل الانصراف أولاً قبل بدء فترة جديدة.');
        }
    }

    public function clockOut(Employee $employee, ?string $ip = null): AttendanceEmployee
    {
        $rules = $this->rules();
        $this->guardIp($rules, $ip);

        return DB::transaction(function () use ($employee, $rules) {
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            $row = $this->openSession($employee);
            if (!$row) {
                throw new DomainException('لا يوجد تسجيل حضور مفتوح لإنهائه. سجّل الحضور أولاً.');
            }

            $nowUtc = $this->nowUtc();
            $in = $this->moment($row, 'in');
            $local = $nowUtc->copy()->setTimezone($rules['zone']);

            $row->clock_out = $local->format('H:i:s');
            $row->clock_out_at = $nowUtc->format('Y-m-d H:i:s');
            $row->worked_seconds = (int) $in->diffInSeconds($nowUtc, true);
            $row->open_key = null;
            $row->fill($this->legacyTimings($row->date, $local, $rules));
            $row->save();

            return $row;
        });
    }

    /**
     * Corrects or creates a record on behalf of an employee.
     * Times are entered in the company's timezone. Callers log the reason.
     */
    public function saveManual(?AttendanceEmployee $row, Employee $employee, string $date, ?string $in, ?string $out): AttendanceEmployee
    {
        $rules = $this->rules();
        $inLocal = $in ? Carbon::parse($date . ' ' . $in, $rules['zone']) : null;
        $outLocal = $out ? Carbon::parse($date . ' ' . $out, $rules['zone']) : null;

        if ($inLocal && $outLocal && $outLocal->lessThanOrEqualTo($inLocal)) {
            // Shift crossed midnight.
            $outLocal->addDay();
        }
        if (!$inLocal && $outLocal) {
            throw new DomainException('حدد وقت الدخول قبل وقت الخروج.');
        }
        if ($inLocal && $inLocal->greaterThan($this->nowLocal())) {
            throw new DomainException('لا يمكن تسجيل وقت دخول في المستقبل.');
        }

        $row = $row ?: new AttendanceEmployee();
        $row->employee_id = $employee->id;
        $row->date = $date;
        $row->status = $inLocal ? 'Present' : 'Leave';
        $row->created_by = $row->created_by ?: $this->companyId;
        $row->source = 'admin';
        $row->total_rest = '00:00:00';
        $row->clock_in = $inLocal ? $inLocal->format('H:i:s') : '00:00:00';
        $row->clock_out = $outLocal ? $outLocal->format('H:i:s') : '00:00:00';
        $row->clock_in_at = $inLocal?->copy()->utc()->format('Y-m-d H:i:s');
        $row->clock_out_at = $outLocal?->copy()->utc()->format('Y-m-d H:i:s');
        $row->worked_seconds = ($inLocal && $outLocal) ? (int) $inLocal->diffInSeconds($outLocal, true) : null;
        $row->open_key = null;
        $row->late = $inLocal ? $this->lateClock($date, $inLocal, $rules) : '00:00:00';
        if ($outLocal) {
            $row->fill($this->legacyTimings($date, $outLocal, $rules));
        } else {
            $row->early_leaving = '00:00:00';
            $row->overtime = '00:00:00';
        }
        $row->save();

        return $row;
    }

    private function guardIp(array $rules, ?string $ip): void
    {
        if ($rules['ip_restrict'] && $ip) {
            $allowed = \App\Models\IpRestrict::where('created_by', $this->companyId)->where('ip', $ip)->exists();
            if (!$allowed) {
                throw new DomainException('هذا الجهاز خارج الشبكة المسموح بتسجيل الحضور منها.');
            }
        }
    }

    private function lateClock(string $date, Carbon $inLocal, array $rules): string
    {
        $expected = Carbon::parse($date . ' ' . $rules['start'], $rules['zone']);
        $seconds = max(0, $expected->diffInSeconds($inLocal, false));

        return $this->clock((int) $seconds);
    }

    private function legacyTimings($date, Carbon $outLocal, array $rules): array
    {
        $day = Carbon::parse($date)->toDateString();
        $end = Carbon::parse($day . ' ' . $rules['end'], $rules['zone']);
        $diff = (int) $end->diffInSeconds($outLocal, false);

        return [
            'early_leaving' => $this->clock(max(0, -$diff)),
            'overtime' => $this->clock(max(0, $diff)),
        ];
    }

    private function clock(int $seconds): string
    {
        $seconds = min($seconds, 86399);

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** What the clock page needs. All durations come from stored values, never the browser. */
    public function stateFor(Employee $employee): array
    {
        $rules = $this->rules();
        $today = $this->nowLocal()->toDateString();
        $open = $this->openSession($employee);
        $nowUtc = $this->nowUtc();

        $sessions = AttendanceEmployee::where('employee_id', $employee->id)
            ->whereDate('date', $today)
            ->where('clock_in', '!=', '00:00:00')
            ->orderBy('id')
            ->get();

        $finished = 0;
        foreach ($sessions as $row) {
            if ($open && $row->id === $open->id) {
                continue;
            }
            $finished += $this->secondsOf($row);
        }

        $running = $open ? (int) $this->moment($open, 'in')->diffInSeconds($nowUtc, true) : 0;

        $day = $this->buildDay($employee, $today, $sessions, collect(), collect(), $rules);

        return [
            'is_open' => (bool) $open,
            'open_id' => $open?->id,
            'open_since_epoch' => $open ? $this->moment($open, 'in')->getTimestamp() : null,
            'server_epoch' => $nowUtc->getTimestamp(),
            'finished_seconds' => $finished,
            'running_seconds' => $running,
            'total_seconds' => $finished + $running,
            'sessions' => $sessions,
            'first_in' => $sessions->first() ? $this->localTime($sessions->first(), 'in') : null,
            'last_out' => (!$open && $sessions->last()) ? $this->localTime($sessions->last(), 'out') : null,
            'status' => $day['status'],
            'late_minutes' => $day['late_minutes'],
            'rules' => $rules,
        ];
    }

    public function secondsOf(AttendanceEmployee $row): int
    {
        if ($row->worked_seconds !== null) {
            return (int) $row->worked_seconds;
        }

        $in = $this->moment($row, 'in');
        $out = $this->moment($row, 'out');
        if (!$in || !$out) {
            return 0;
        }

        return (int) $in->diffInSeconds($out, true);
    }

    public function localTime(AttendanceEmployee $row, string $which): ?string
    {
        $moment = $this->moment($row, $which);

        return $moment ? $moment->setTimezone($this->rules()['zone'])->format('H:i') : null;
    }

    /**
     * One row per employee per calendar day, including the days with no record,
     * which is the only way absences can be counted.
     *
     * @param Collection<int,Employee> $employees
     */
    public function report(Collection $employees, string $from, string $to): Collection
    {
        $rules = $this->rules();
        $ids = $employees->pluck('id');

        $rows = AttendanceEmployee::whereIn('employee_id', $ids)
            ->whereBetween('date', [$from, $to])
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($row) => $row->employee_id . '|' . Carbon::parse($row->date)->toDateString());

        $leaves = Leave::whereIn('employee_id', $ids)
            ->where('status', 'Approved')
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->get()
            ->groupBy('employee_id');

        // Approved permissions (late arrival or early leave) excuse the late flag on that day.
        $permissions = \Illuminate\Support\Facades\Schema::hasTable('edata_requests')
            ? \Illuminate\Support\Facades\DB::table('edata_requests')->whereIn('employee_id', $ids)->where('kind', 'permission')
                ->where('status', 'approved')->whereBetween('date', [$from, $to])->get()
                ->mapWithKeys(fn ($p) => [$p->employee_id . '|' . Carbon::parse($p->date)->toDateString() => true])
            : collect();

        $holidays = Holiday::where('created_by', $this->companyId)
            ->where('date', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->where('end_date', '>=', $from)->orWhereNull('end_date');
            })
            ->get();

        $days = [];
        foreach ($employees as $employee) {
            $cursor = Carbon::parse($from);
            $last = Carbon::parse($to);
            while ($cursor->lte($last)) {
                $date = $cursor->toDateString();
                $days[] = $this->buildDay(
                    $employee,
                    $date,
                    $rows[$employee->id . '|' . $date] ?? collect(),
                    $leaves[$employee->id] ?? collect(),
                    $holidays,
                    $rules,
                    isset($permissions[$employee->id . '|' . $date])
                );
                $cursor->addDay();
            }
        }

        return collect($days)->sortByDesc('date')->values();
    }

    private function buildDay(Employee $employee, string $date, Collection $sessions, Collection $leaves, Collection $holidays, array $rules, bool $permitted = false): array
    {
        $day = Carbon::parse($date);
        $localNow = $this->nowLocal();
        $today = $localNow->toDateString();
        $sessions = $sessions->filter(fn ($row) => $row->clock_in !== '00:00:00' && $row->clock_in !== null)->values();

        $base = [
            'date' => $date,
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'user_id' => $employee->user_id,
            'in' => null,
            'out' => null,
            'seconds' => 0,
            'late_minutes' => 0,
            'overtime_seconds' => 0,
            'sessions' => $sessions,
            'flags' => [],
            'row_id' => $sessions->first()->id ?? null,
        ];

        if ($sessions->isNotEmpty()) {
            $first = $sessions->first();
            $hasOpen = false;
            $hasMissing = false;
            $seconds = 0;

            foreach ($sessions as $row) {
                $isOpen = !$this->moment($row, 'out');
                if ($isOpen) {
                    if ($this->isStale($row)) {
                        $hasMissing = true;
                    } else {
                        $hasOpen = true;
                        $seconds += (int) $this->moment($row, 'in')->diffInSeconds($this->nowUtc(), true);
                    }
                } else {
                    $seconds += $this->secondsOf($row);
                }
            }

            $expected = Carbon::parse($date . ' ' . $rules['start'], $rules['zone']);
            $firstIn = $this->moment($first, 'in');
            $lateSeconds = $firstIn ? (int) $expected->diffInSeconds($firstIn->copy()->setTimezone($rules['zone']), false) : 0;
            $lateMinutes = $lateSeconds > $rules['grace'] * 60 ? (int) ceil($lateSeconds / 60) : 0;
            $overtime = max(0, $seconds - $rules['required_seconds']);

            $flags = [];
            if ($permitted) {
                $lateMinutes = 0;
                $flags[] = 'permission';
            }
            if ($lateMinutes > 0) {
                $flags[] = 'late';
            }
            if ($overtime > 0 && !$hasOpen) {
                $flags[] = 'overtime';
            }
            if ($hasMissing) {
                $flags[] = 'missing_out';
            }

            $status = $hasOpen ? 'working' : ($hasMissing ? 'missing_out' : ($lateMinutes > 0 ? 'late' : ($overtime > 0 ? 'overtime' : 'present')));

            $lastRow = $sessions->last();

            return array_merge($base, [
                'status' => $status,
                'in' => $this->localTime($first, 'in'),
                'out' => $hasOpen ? null : $this->localTime($lastRow, 'out'),
                'seconds' => $seconds,
                'late_minutes' => $lateMinutes,
                'overtime_seconds' => $overtime,
                'flags' => $flags,
            ]);
        }

        // No clock-in on this day: decide why.
        foreach ($leaves as $leave) {
            if ($date >= Carbon::parse($leave->start_date)->toDateString() && $date <= Carbon::parse($leave->end_date)->toDateString()) {
                return array_merge($base, ['status' => 'leave']);
            }
        }
        foreach ($holidays as $holiday) {
            $start = Carbon::parse($holiday->date)->toDateString();
            $end = $holiday->end_date ? Carbon::parse($holiday->end_date)->toDateString() : $start;
            if ($date >= $start && $date <= $end) {
                return array_merge($base, ['status' => 'holiday']);
            }
        }
        if (!in_array($day->dayOfWeek, $rules['work_days'], true)) {
            return array_merge($base, ['status' => 'weekend']);
        }

        $hired = $this->startOfEmployment($employee);
        if ($hired && $date < $hired) {
            return array_merge($base, ['status' => 'weekend']);
        }
        if ($date > $today) {
            return array_merge($base, ['status' => 'not_yet']);
        }
        if ($date === $today) {
            $cutoff = Carbon::parse($date . ' ' . $rules['start'], $rules['zone'])->addMinutes($rules['grace']);
            if ($localNow->lessThanOrEqualTo($cutoff) || $localNow->format('H:i') < $rules['end']) {
                return array_merge($base, ['status' => 'not_yet']);
            }
        }

        return array_merge($base, ['status' => 'absent']);
    }

    private function startOfEmployment(Employee $employee): ?string
    {
        foreach ([$employee->company_doj, $employee->created_at] as $candidate) {
            if (!empty($candidate)) {
                try {
                    return Carbon::parse($candidate)->toDateString();
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        return null;
    }

    /** Totals over a list of day rows, for summaries and profile cards. */
    public function totals(Collection $days): array
    {
        $expected = $days->whereIn('status', ['present', 'late', 'overtime', 'working', 'missing_out', 'absent'])->count();
        $attended = $days->whereIn('status', ['present', 'late', 'overtime', 'working', 'missing_out'])->count();

        return [
            'seconds' => (int) $days->sum('seconds'),
            'overtime_seconds' => (int) $days->sum('overtime_seconds'),
            'attended' => $attended,
            'expected' => $expected,
            'absent' => $days->where('status', 'absent')->count(),
            'late' => $days->filter(fn ($d) => in_array('late', $d['flags'], true))->count(),
            'leave' => $days->where('status', 'leave')->count(),
            'missing_out' => $days->filter(fn ($d) => in_array('missing_out', $d['flags'], true))->count(),
            'overtime' => $days->filter(fn ($d) => in_array('overtime', $d['flags'], true))->count(),
            'rate' => $expected > 0 ? (int) round($attended / $expected * 100) : null,
        ];
    }

    /** Today's board for the whole company. */
    public function todayBoard(Collection $employees): array
    {
        $today = $this->nowLocal()->toDateString();
        $days = $this->report($employees, $today, $today);

        return [
            'days' => $days,
            'working' => $days->where('status', 'working')->values(),
            'present' => $days->whereIn('status', ['present', 'late', 'overtime', 'working', 'missing_out'])->count(),
            'late' => $days->filter(fn ($d) => in_array('late', $d['flags'], true))->values(),
            'absent' => $days->where('status', 'absent')->values(),
            'not_yet' => $days->where('status', 'not_yet')->values(),
            'leave' => $days->where('status', 'leave')->values(),
        ];
    }
}
