<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TimelineEntry;
use App\Services\ProjectMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ProjectMetricsTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_progress_excludes_cancelled_tasks_and_next_stage_comes_from_the_timeline(): void
    {
        Date::setTestNow('2026-08-12 10:00:00');
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $done = $this->makeStatus('task', 'metrics-done', 'done');
        $open = $this->makeStatus('task', 'metrics-open', 'open');
        $cancelled = $this->makeStatus('task', 'metrics-cancelled', 'cancelled');

        $this->makeTask($project, $done->id, 'منجزة', now()->subDay());
        $this->makeTask($project, $open->id, 'مفتوحة', now()->addDay());
        $this->makeTask($project, $cancelled->id, 'ملغاة', now()->subDays(2));

        TimelineEntry::query()->create([
            'project_id' => $project->id,
            'kind' => 'milestone',
            'title' => 'مرحلة ملغاة',
            'starts_at' => now()->addHour(),
            'status' => 'cancelled',
        ]);
        TimelineEntry::query()->create([
            'project_id' => $project->id,
            'kind' => 'milestone',
            'title' => 'مرحلة مؤرشفة',
            'starts_at' => now()->addHours(2),
            'status' => 'planned',
            'archived_at' => now(),
        ]);
        $nextStage = TimelineEntry::query()->create([
            'project_id' => $project->id,
            'kind' => 'phase',
            'title' => 'مرحلة التحليل',
            'starts_at' => now()->addDays(2),
            'status' => 'planned',
        ]);
        TimelineEntry::query()->create([
            'project_id' => $project->id,
            'kind' => 'delivery',
            'title' => 'التسليم النهائي',
            'starts_at' => now()->addDays(4),
            'status' => 'planned',
        ]);

        $service = app(ProjectMetrics::class);
        $projectWithMetrics = $service->withMetrics(Project::query()->whereKey($project))->firstOrFail();
        $metrics = $service->fromCounts($projectWithMetrics);

        $this->assertSame(2, $metrics['total_tasks']);
        $this->assertSame(1, $metrics['open_tasks']);
        $this->assertSame(50, $metrics['progress']);
        $this->assertSame('attention', $metrics['health']);
        $this->assertSame($nextStage->id, $metrics['next_stage']['id']);
        $this->assertSame('مرحلة التحليل', $metrics['next_stage']['title']);
        $this->assertSame('phase', $metrics['next_stage']['kind']);
    }

    public function test_health_filter_uses_the_same_health_formula_as_the_metric_payload(): void
    {
        Date::setTestNow('2026-08-12 10:00:00');
        $manager = $this->makeUser('project_manager');
        $status = $this->makeStatus('project', 'metrics-active', 'in_progress');
        $danger = $this->makeProject($manager, $status);
        $attention = $this->makeProject($manager, $status);
        $healthy = $this->makeProject($manager, $status);
        $open = $this->makeStatus('task', 'metrics-health-open', 'open');

        Risk::query()->create([
            'project_id' => $danger->id,
            'title' => 'خطر مرتفع',
            'probability' => 4,
            'impact' => 4,
            'status' => 'open',
        ]);
        $this->makeTask($attention, $open->id, 'مهمة مفتوحة', now()->addDay());

        $service = app(ProjectMetrics::class);
        $base = Project::query()->whereIn('id', [$danger->id, $attention->id, $healthy->id]);

        $this->assertSame([$danger->id], $service->whereHealth(clone $base, 'danger')->pluck('id')->all());
        $this->assertSame([$attention->id], $service->whereHealth(clone $base, 'attention')->pluck('id')->all());
        $this->assertSame([$healthy->id], $service->whereHealth(clone $base, 'healthy')->pluck('id')->all());
    }

    private function makeTask(Project $project, int $statusId, string $title, mixed $dueAt): Task
    {
        return Task::query()->create([
            'project_id' => $project->id,
            'code' => 'MET-'.fake()->unique()->numerify('#####'),
            'title' => $title,
            'status_id' => $statusId,
            'priority' => 'medium',
            'start_at' => now()->subDay(),
            'due_at' => $dueAt,
        ]);
    }
}
