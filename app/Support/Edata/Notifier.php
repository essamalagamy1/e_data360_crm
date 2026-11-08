<?php

namespace App\Support\Edata;

use App\Models\AttendanceEmployee;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * In-app notifications only. Uses the existing notifications table so old rows
 * stay valid; new rows carry their text and link in the JSON data column.
 */
class Notifier
{
    /**
     * @param int|int[] $userIds
     */
    public static function send(int|array $userIds, string $type, string $title, ?string $body = null, ?string $url = null, ?string $dedupe = null, bool $includeSelf = false): void
    {
        $current = Auth::id();

        foreach (array_unique(array_filter((array) $userIds)) as $userId) {
            if (!$includeSelf && (int) $userId === (int) $current) {
                continue;
            }

            if ($dedupe && Notification::where('user_id', $userId)->where('dedupe_key', $dedupe)->exists()) {
                continue;
            }

            Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'data' => json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE),
                'is_read' => 0,
                'dedupe_key' => $dedupe,
            ]);
        }
    }

    /** The owner plus everyone holding the given permission in this company. */
    public static function holders(int $companyId, string $permission): array
    {
        $ids = [$companyId];

        try {
            $holders = User::where('created_by', $companyId)->permission($permission)->pluck('id')->all();
            $ids = array_merge($ids, $holders);
        } catch (\Throwable $e) {
            // Permission not created yet: fall back to the owner alone.
        }

        return array_values(array_unique($ids));
    }

    /**
     * Date-driven alerts (due soon, overdue, forgotten clock-outs). Runs at most
     * once an hour per company from normal page traffic, so it works on shared
     * hosting with no cron. `php artisan edata:alerts` does the same from cron.
     */
    public static function heartbeat(int $companyId): void
    {
        Cache::remember('edata_alerts_' . $companyId, now()->addHour(), function () use ($companyId) {
            self::runChecks($companyId);

            return true;
        });
    }

    public static function runChecks(int $companyId): void
    {
        $today = Carbon::today();
        $dueSoonDays = (int) config('edata.tasks.due_soon_days');

        // Tasks
        $doneStages = TaskRules::doneStageIds($companyId);
        $tasks = ProjectTask::query()
            ->whereIn('project_id', Project::where('created_by', $companyId)->whereIn('status', Labels::PROJECT_OPEN)->select('id'))
            ->whereNull('cancelled_at')
            ->where('is_complete', 0)
            ->whereNotIn('stage_id', $doneStages)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', $today->copy()->addDays($dueSoonDays))
            ->whereDate('end_date', '>=', $today->copy()->subDays(30))
            ->limit(300)
            ->get();

        foreach ($tasks as $task) {
            $assignees = TaskRules::assigneeIds($task);
            $end = Carbon::parse($task->end_date);
            $url = route('edata.tasks.show', $task->id, false);

            if ($end->lt($today)) {
                self::send($assignees, 'task_overdue', 'مهمة متأخرة', $task->name, $url, 'task_overdue:' . $task->id, true);
            } else {
                $when = $end->isSameDay($today) ? 'اليوم' : ($end->isSameDay($today->copy()->addDay()) ? 'غداً' : Labels::date($end));
                self::send($assignees, 'task_due_soon', 'موعد تسليم قريب: ' . $when, $task->name, $url, 'task_due:' . $task->id, true);
            }
        }

        // Invoices
        $finance = self::holders($companyId, 'manage invoice');
        $invoices = Invoice::where('created_by', $companyId)
            ->whereNull('cancelled_at')
            ->whereIn('status', [1, 2, 3])
            ->whereDate('due_date', '<=', $today->copy()->addDays((int) config('edata.invoices.due_soon_days')))
            ->whereDate('due_date', '>=', $today->copy()->subDays(30))
            ->limit(200)
            ->get();

        foreach ($invoices as $invoice) {
            $number = Fmt::invoiceNumber($invoice->invoice_id, $companyId);
            $url = route('edata.invoices.show', $invoice->id, false);

            if (Carbon::parse($invoice->due_date)->lt($today)) {
                self::send($finance, 'invoice_overdue', 'فاتورة متأخرة السداد', $number, $url, 'invoice_overdue:' . $invoice->id, true);
            } else {
                self::send($finance, 'invoice_due_soon', 'فاتورة قرب موعد استحقاقها', $number, $url, 'invoice_due:' . $invoice->id, true);
            }
        }

        // Forgotten clock-outs from earlier days
        $employees = Employee::where('created_by', $companyId)->get()->keyBy('id');
        $open = AttendanceEmployee::whereIn('employee_id', $employees->keys())
            ->whereDate('date', '<', $today)
            ->whereDate('date', '>=', $today->copy()->subDays(3))
            ->where('clock_in', '!=', '00:00:00')
            ->where('clock_out', '00:00:00')
            ->get();

        $managers = self::holders($companyId, 'manage attendance');
        foreach ($open as $row) {
            $employee = $employees[$row->employee_id] ?? null;
            if (!$employee) {
                continue;
            }
            $key = 'missing_out:' . $row->id;
            $dayText = Labels::date($row->date);
            self::send((int) $employee->user_id, 'attendance_missing_out', 'لم تسجل الانصراف يوم ' . $dayText, 'راجع مديرك لتصحيح السجل.', route('edata.me.attendance', [], false), $key, true);
            self::send($managers, 'attendance_missing_out', $employee->name . ' لم يسجل الانصراف', $dayText, route('edata.attendance.index', ['from' => $row->date, 'to' => $row->date], false), $key . ':m', true);
        }
    }
}
