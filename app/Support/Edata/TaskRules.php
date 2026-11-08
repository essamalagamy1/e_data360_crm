<?php

namespace App\Support\Edata;

use App\Models\ProjectTask;
use App\Models\TaskStage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Task status is built from the company's stages (the kanban columns they already
 * use) plus two conditions that are not stages: overdue and cancelled.
 */
class TaskRules
{
    private static array $stages = [];

    public static function stages(int $companyId): Collection
    {
        return self::$stages[$companyId] ??= TaskStage::where('created_by', $companyId)->orderBy('order')->get();
    }

    public static function flush(): void
    {
        self::$stages = [];
    }

    /** The final column counts as done. */
    public static function doneStageIds(int $companyId): array
    {
        $last = self::stages($companyId)->last();

        return $last ? [(int) $last->id] : [];
    }

    public static function firstStageId(int $companyId): int
    {
        return (int) (self::stages($companyId)->first()->id ?? 0);
    }

    public static function isDone(ProjectTask $task, int $companyId): bool
    {
        return (int) $task->is_complete === 1 || in_array((int) $task->stage_id, self::doneStageIds($companyId), true);
    }

    public static function isOverdue(ProjectTask $task, int $companyId): bool
    {
        if ($task->cancelled_at || self::isDone($task, $companyId) || empty($task->end_date)) {
            return false;
        }

        return Carbon::parse($task->end_date)->endOfDay()->isPast();
    }

    /** @return array{0:string,1:string} text and tone */
    public static function state(ProjectTask $task, int $companyId): array
    {
        if ($task->cancelled_at) {
            return ['ملغاة', 'neutral'];
        }
        if (self::isDone($task, $companyId)) {
            return ['مكتملة', 'good'];
        }
        if (self::isOverdue($task, $companyId)) {
            return ['متأخرة', 'bad'];
        }

        $stage = self::stages($companyId)->firstWhere('id', (int) $task->stage_id);

        return [$stage ? self::stageName($stage->name) : 'جديدة', 'info'];
    }

    /** Stage names are the company's own text. The four defaults get Arabic names. */
    public static function stageName(?string $name): string
    {
        $map = [
            'To Do' => 'جديدة',
            'Todo' => 'جديدة',
            'In Progress' => 'قيد التنفيذ',
            'Review' => 'قيد المراجعة',
            'Done' => 'مكتملة',
        ];

        return $map[trim((string) $name)] ?? (string) $name;
    }

    public static function assigneeIds(ProjectTask $task): array
    {
        return array_values(array_map('intval', array_filter(explode(',', (string) $task->assign_to))));
    }

    public static function needsAttention(ProjectTask $task, int $companyId): ?string
    {
        if ($task->cancelled_at || self::isDone($task, $companyId)) {
            return null;
        }
        if (self::isOverdue($task, $companyId)) {
            return 'تأخرت عن موعدها';
        }
        if (empty(self::assigneeIds($task))) {
            return 'بلا مسؤول';
        }
        if ($task->priority === 'critical' && (int) $task->progress === 0) {
            return 'عاجلة ولم تبدأ';
        }
        if ($task->updated_at && $task->updated_at->lt(now()->subDays((int) config('edata.tasks.stale_after_days')))) {
            return 'لم تتحدث منذ ' . (int) $task->updated_at->diffInDays(now(), true) . ' يوم';
        }

        return null;
    }
}
