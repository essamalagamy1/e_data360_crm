<?php

namespace App\Support\Edata;

use App\Models\Project;
use App\Models\ProjectTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Task counts and progress for a list of projects, in one grouped query instead
 * of a query per card.
 */
class ProjectStats
{
    /** @return array<int,array{total:int,done:int,overdue:int,progress:int}> */
    public static function forIds(Collection $ids, int $companyId): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $done = TaskRules::doneStageIds($companyId) ?: [0];
        $placeholders = implode(',', array_fill(0, count($done), '?'));
        $today = Carbon::today()->toDateString();

        $rows = ProjectTask::whereIn('project_id', $ids)
            ->whereNull('cancelled_at')
            ->selectRaw(
                "project_id,
                 COUNT(*) as total,
                 SUM(CASE WHEN is_complete = 1 OR stage_id IN ($placeholders) THEN 1 ELSE 0 END) as done,
                 SUM(CASE WHEN is_complete = 0 AND stage_id NOT IN ($placeholders) AND end_date IS NOT NULL AND end_date < ? THEN 1 ELSE 0 END) as overdue",
                array_merge($done, $done, [$today])
            )
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        $result = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $total = (int) ($row->total ?? 0);
            $doneCount = (int) ($row->done ?? 0);
            $result[$id] = [
                'total' => $total,
                'done' => $doneCount,
                'overdue' => (int) ($row->overdue ?? 0),
                'progress' => $total > 0 ? (int) round($doneCount / $total * 100) : 0,
            ];
        }

        return $result;
    }

    /** A manual percentage wins when someone set one; a finished project is always 100. */
    public static function progress(Project $project, array $stats): int
    {
        if ($project->status === 'complete') {
            return 100;
        }
        if ($project->manual_progress !== null) {
            return (int) $project->manual_progress;
        }

        return $stats['progress'] ?? 0;
    }

    public static function isDelayed(Project $project): bool
    {
        return in_array($project->status, Labels::PROJECT_OPEN, true)
            && !empty($project->end_date)
            && Carbon::parse($project->end_date)->endOfDay()->isPast();
    }

    public static function daysLate(Project $project): int
    {
        return (int) Carbon::parse($project->end_date)->endOfDay()->diffInDays(now(), true);
    }
}
