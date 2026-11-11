<?php

namespace App\Support\Edata;

use App\Models\AttendanceEmployee;
use App\Models\EdataKudos;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The employee's requests, daily plan, announcements, task timers and monthly
 * summary. Everything is read from real records.
 */
class EmployeeServices
{
    /** Who decides on attendance corrections, permissions and leave: the owner and attendance editors. */
    public static function approverIds(int $company): array
    {
        return array_values(array_unique(Notifier::holders($company, 'manage leave')));
    }

    public static function canApprove(User $user): bool
    {
        return Access::isOwner($user) || $user->can('manage leave');
    }

    /** The permission behind each service, set per role from the roles screen. */
    public const FEATURES = [
        'requests' => 'create self request',
        'plan' => 'use day plan',
        'timer' => 'use task timer',
        'summary' => 'view month summary',
        'daily' => 'view daily report',
    ];

    public static function allows(User $user, string $feature): bool
    {
        return Access::isOwner($user) || $user->can(self::FEATURES[$feature]);
    }

    public static function pendingCount(int $company): int
    {
        $requests = DB::table('edata_requests')->where('company_id', $company)->where('status', 'pending')->count();
        $leaves = Leave::where('created_by', $company)->where('status', 'Pending')->count();

        return $requests + $leaves;
    }

    /** Leave types with what is left this year. A type with no day limit shows no balance. */
    public static function leaveBalances(Employee $employee, int $company, Carbon $today): Collection
    {
        $types = DB::table('leave_types')->where('created_by', $company)->orderBy('title')->get(['id', 'title', 'days']);
        $used = Leave::where('employee_id', $employee->id)
            ->where('status', 'Approved')
            ->whereYear('start_date', $today->year)
            ->get()
            ->groupBy('leave_type_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => (float) $r->total_leave_days));

        return $types->map(fn ($type) => (object) [
            'id' => $type->id,
            'title' => $type->title,
            'days' => (int) $type->days,
            'used' => (float) ($used[$type->id] ?? 0),
            'left' => (int) $type->days > 0 ? max(0, (int) $type->days - (float) ($used[$type->id] ?? 0)) : null,
        ]);
    }

    /** The employee's own recent requests of every kind, newest first. */
    public static function myRequests(Employee $employee): Collection
    {
        $requests = DB::table('edata_requests')->where('employee_id', $employee->id)->orderByDesc('id')->limit(8)->get()
            ->map(fn ($r) => (object) [
                'kind' => $r->kind, 'date' => $r->date, 'until' => null, 'from' => $r->time_from, 'to' => $r->time_to,
                'reason' => $r->reason, 'status' => $r->status, 'note' => $r->review_note, 'created' => $r->created_at,
            ]);
        $leaves = Leave::where('employee_id', $employee->id)->orderByDesc('id')->limit(8)->get()
            ->map(fn ($l) => (object) [
                'kind' => 'leave', 'date' => $l->start_date, 'until' => $l->end_date, 'from' => null, 'to' => null,
                'reason' => $l->leave_reason, 'status' => self::leaveStatus($l->status), 'note' => $l->remark, 'created' => $l->created_at,
            ]);

        return $requests->concat($leaves)->sortByDesc('created')->take(8)->values();
    }

    public static function leaveStatus(?string $status): string
    {
        return match ($status) {
            'Approved' => 'approved',
            'Reject', 'Rejected' => 'rejected',
            default => 'pending',
        };
    }

    public static function dayLog(Employee $employee, string $date): ?object
    {
        return DB::table('edata_daily_logs')->where('employee_id', $employee->id)->where('date', $date)->first();
    }

    public static function planIds(?object $log): array
    {
        return $log && $log->plan ? array_map('intval', (array) json_decode($log->plan, true)) : [];
    }

    /** Announcements still showing, with whether this person has read each. */
    public static function announcements(int $company, int $userId, int $limit = 3): Collection
    {
        $today = Carbon::today()->toDateString();

        return DB::table('edata_announcements as a')
            ->leftJoin('edata_announcement_reads as r', fn ($j) => $j->on('r.announcement_id', '=', 'a.id')->where('r.user_id', $userId))
            ->where('a.company_id', $company)
            ->whereNull('a.archived_at')
            ->where(fn ($q) => $q->whereNull('a.show_until')->orWhere('a.show_until', '>=', $today))
            ->orderByDesc('a.id')
            ->limit($limit)
            ->get(['a.*', 'r.read_at']);
    }

    public static function runningTimer(int $userId): ?object
    {
        return DB::table('edata_task_timers')->where('user_id', $userId)->whereNull('stopped_at')->orderByDesc('id')->first();
    }

    /** Recorded seconds per task, including a timer still running. */
    public static function taskSeconds(array $taskIds): array
    {
        if (!$taskIds) {
            return [];
        }
        $now = Carbon::now('UTC');
        $totals = [];
        foreach (DB::table('edata_task_timers')->whereIn('task_id', $taskIds)->get() as $row) {
            $seconds = $row->stopped_at ? (int) $row->seconds : (int) Carbon::parse($row->started_at, 'UTC')->diffInSeconds($now, true);
            $totals[$row->task_id] = ($totals[$row->task_id] ?? 0) + $seconds;
        }

        return $totals;
    }

    public static function stopTimer(object $timer): void
    {
        $stop = Carbon::now('UTC');
        DB::table('edata_task_timers')->where('id', $timer->id)->update([
            'stopped_at' => $stop->format('Y-m-d H:i:s'),
            'seconds' => (int) Carbon::parse($timer->started_at, 'UTC')->diffInSeconds($stop, true),
            'updated_at' => now(),
        ]);
    }

    /** One month of an employee's work, for the monthly summary. */
    public static function monthSummary(User $user, ?Employee $employee, int $company, Carbon $month): array
    {
        $service = AttendanceService::for($company);
        $zone = $service->rules()['zone'];
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $today = Carbon::now($zone);
        $last = $end->greaterThan($today) ? $today : $end;

        $totals = ['seconds' => 0, 'attended' => 0, 'late' => 0, 'expected' => 0, 'rate' => null, 'leave' => 0];
        if ($employee && $start->lessThanOrEqualTo($last)) {
            $days = $service->report(collect([$employee]), $start->toDateString(), $last->toDateString());
            $totals = $service->totals($days);
        }

        $done = Access::tasks($user)
            ->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$user->id])
            ->where('is_complete', 1)
            ->whereBetween('marked_at', [$start->toDateString(), $end->toDateString()])
            ->count();

        $timerSeconds = (int) DB::table('edata_task_timers')
            ->where('user_id', $user->id)
            ->whereNotNull('stopped_at')
            ->whereBetween('started_at', [$start->copy()->utc()->format('Y-m-d H:i:s'), $end->copy()->utc()->format('Y-m-d H:i:s')])
            ->sum('seconds');

        return [
            'month' => $start,
            'seconds' => (int) $totals['seconds'],
            'attended' => (int) $totals['attended'],
            'on_time' => max(0, (int) $totals['attended'] - (int) $totals['late']),
            'rate' => $totals['rate'],
            'leave' => (int) $totals['leave'],
            'tasks' => $done,
            'timer_seconds' => $timerSeconds,
            'kudos' => EdataKudos::where('to_user_id', $user->id)->whereBetween('created_at', [$start, $end->copy()->endOfDay()])->count(),
        ];
    }
}
