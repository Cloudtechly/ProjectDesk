<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

class WeeklyScheduleBuilder
{
    private const MAX_VISIBLE_BARS_PER_PROJECT = 50;

    private const MAX_VISIBLE_LANES = 3;

    private const MAX_LOADED_ITEMS_PER_TYPE_AND_PROJECT = 50;

    /** @return array{weekStart: string, weekEnd: string, days: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>} */
    public function build(User $user, ?string $selectedDate = null): array
    {
        $businessTimezone = (string) config('project-desk.business_timezone', 'Africa/Tripoli');
        $anchor = $selectedDate
            ? CarbonImmutable::parse($selectedDate, $businessTimezone)
            : CarbonImmutable::instance(Date::now())->setTimezone($businessTimezone);
        $weekStart = $anchor->startOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $weekEnd = $weekStart->addDays(6)->endOfDay();
        $queryWeekStart = $weekStart->utc();
        $queryWeekEnd = $weekEnd->utc();

        /** @var Collection<int, Project> $projects */
        $projects = Project::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->with([
                'tasks' => fn ($query) => $query
                    ->whereNull('archived_at')
                    ->where('start_at', '<=', $queryWeekEnd)
                    ->where('due_at', '>=', $queryWeekStart)
                    ->orderBy('start_at')
                    ->orderBy('due_at')
                    ->limit(self::MAX_LOADED_ITEMS_PER_TYPE_AND_PROJECT)
                    ->with('status'),
                'timelineEntries' => fn ($query) => $query
                    ->whereNull('archived_at')
                    ->where('kind', 'meeting')
                    ->where('starts_at', '<=', $queryWeekEnd)
                    ->where(fn ($ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>=', $queryWeekStart))
                    ->orderBy('starts_at')
                    ->limit(self::MAX_LOADED_ITEMS_PER_TYPE_AND_PROJECT),
            ])
            ->withCount([
                'tasks as weekly_tasks_count' => fn ($query) => $query
                    ->whereNull('archived_at')
                    ->where('start_at', '<=', $queryWeekEnd)
                    ->where('due_at', '>=', $queryWeekStart),
                'timelineEntries as weekly_meetings_count' => fn ($query) => $query
                    ->whereNull('archived_at')
                    ->where('kind', 'meeting')
                    ->where('starts_at', '<=', $queryWeekEnd)
                    ->where(fn ($ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>=', $queryWeekStart)),
            ])
            ->orderBy('name')
            ->get();

        $today = CarbonImmutable::instance(Date::now())->setTimezone($businessTimezone)->startOfDay();
        $dayLabels = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $days = [];
        for ($day = 0; $day < 7; $day++) {
            $date = $weekStart->addDays($day);
            $days[] = [
                'date' => $date->toDateString(),
                'label' => $dayLabels[$day],
                'dayNumber' => $date->day,
                'isToday' => $date->isSameDay($today),
                'isWeekend' => in_array($date->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true),
            ];
        }

        $rows = $projects->map(function (Project $project) use ($weekStart, $weekEnd, $businessTimezone, $user): array {
            $bars = [];
            $canManageProject = $user->can('update', $project);

            foreach ($project->tasks as $task) {
                $start = CarbonImmutable::instance($task->start_at)->setTimezone($businessTimezone);
                $end = CarbonImmutable::instance($task->due_at)->setTimezone($businessTimezone);
                $placement = $this->placement(
                    id: $task->id,
                    type: 'task',
                    title: $task->title,
                    start: $start,
                    end: $end,
                    weekStart: $weekStart,
                    weekEnd: $weekEnd,
                    status: $task->status->semantic,
                );
                $placement['href'] = $canManageProject
                    ? route('tasks.edit', $task, false)
                    : route('tasks.index', ['project' => $project->id, 'q' => $task->code], false);
                $bars[] = $placement;
            }

            foreach ($project->timelineEntries as $entry) {
                $start = CarbonImmutable::instance($entry->starts_at)->setTimezone($businessTimezone);
                $end = CarbonImmutable::instance($entry->ends_at ?? $entry->starts_at)->setTimezone($businessTimezone);
                $bars[] = $this->placement(
                    id: $entry->id,
                    type: 'meeting',
                    title: $entry->title,
                    start: $start,
                    end: $end,
                    weekStart: $weekStart,
                    weekEnd: $weekEnd,
                    status: $entry->status,
                );
                $bars[array_key_last($bars)]['href'] = route('projects.show', [
                    'project' => $project,
                    'tab' => 'timeline',
                ], false);
            }

            usort($bars, fn (array $left, array $right): int => [$left['startColumn'], -$left['span'], $left['id']] <=> [$right['startColumn'], -$right['span'], $right['id']]);
            $laneEnds = [];
            foreach ($bars as &$bar) {
                $barEnd = $bar['startColumn'] + $bar['span'] - 1;
                $lane = 1;
                while (isset($laneEnds[$lane]) && $laneEnds[$lane] >= $bar['startColumn']) {
                    $lane++;
                }
                $bar['lane'] = $lane;
                $laneEnds[$lane] = $barEnd;
            }
            unset($bar);

            $totalBarCount = (int) $project->getAttribute('weekly_tasks_count')
                + (int) $project->getAttribute('weekly_meetings_count');
            $visibleBars = array_values(array_filter(
                $bars,
                fn (array $bar): bool => $bar['lane'] <= self::MAX_VISIBLE_LANES,
            ));
            $visibleBars = array_slice($visibleBars, 0, self::MAX_VISIBLE_BARS_PER_PROJECT);
            $visibleLaneCount = $visibleBars === []
                ? 0
                : max(array_column($visibleBars, 'lane'));

            return [
                'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
                'bars' => $visibleBars,
                'laneCount' => $visibleLaneCount,
                'totalBarCount' => $totalBarCount,
                'hiddenCount' => $totalBarCount - count($visibleBars),
            ];
        })->values()->all();

        return [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'days' => $days,
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function placement(
        int $id,
        string $type,
        string $title,
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        string $status,
    ): array {
        $clippedStart = $start->lessThan($weekStart) ? $weekStart : $start;
        $clippedEnd = $end->greaterThan($weekEnd) ? $weekEnd : $end;
        $startColumn = (int) $weekStart->startOfDay()->diffInDays($clippedStart->startOfDay()) + 1;
        $span = (int) $clippedStart->startOfDay()->diffInDays($clippedEnd->startOfDay()) + 1;

        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'startColumn' => $startColumn,
            'span' => $span,
            'status' => $status,
            'startsAt' => $start->toIso8601String(),
            'endsAt' => $end->toIso8601String(),
            'continuesBefore' => $start->lessThan($weekStart),
            'continuesAfter' => $end->greaterThan($weekEnd),
        ];
    }
}
