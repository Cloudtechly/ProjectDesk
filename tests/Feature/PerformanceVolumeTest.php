<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class PerformanceVolumeTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_task_list_and_dashboard_remain_bounded_with_ten_thousand_tasks(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $taskStatus = $this->makeStatus('task', 'volume-open', 'open');
        $timestamp = now()->utc()->format('Y-m-d H:i:s');

        $this->insertTasks($project->id, $taskStatus->id, $manager->id, 1, 10, $timestamp);

        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'pragma')) {
                $queryCount++;
            }
        });

        $this->actingAs($manager)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tasks/index')
                ->has('tasks.data', 10)
                ->where('tasks.total', 10));

        $this->get(route('projects.show', ['project' => $project, 'tab' => 'tasks']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('project.tasks', 10)
                ->where('tabPagination.total', 10));

        $this->get(route('dashboard', ['week' => '2026-08-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard'));

        $baselineQueryCount = $queryCount;
        $this->insertTasks($project->id, $taskStatus->id, $manager->id, 11, 10_000, $timestamp);

        $queryCount = 0;
        $startedAt = microtime(true);

        $this->actingAs($manager)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tasks/index')
                ->has('tasks.data', 30)
                ->where('tasks.total', 10_000));

        $this->get(route('projects.show', ['project' => $project, 'tab' => 'tasks']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('project.tasks', 50)
                ->where('tabPagination.total', 10_000)
                ->where('tabPagination.current_page', 1)
                ->where('tabPagination.last_page', 200));

        $this->get(route('dashboard', ['week' => '2026-08-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('summary.activeProjects', 1)
                ->has('tasks', 8)
                ->has('workload', 1)
                ->where('weeklySchedule.rows.0.totalBarCount', 10_000)
                ->where('weeklySchedule.rows.0.hiddenCount', 9_997)
                ->has('weeklySchedule.rows.0.bars', 3));

        $elapsedSeconds = microtime(true) - $startedAt;

        $this->assertLessThanOrEqual(
            $baselineQueryCount + 5,
            $queryCount,
            'يجب أن يبقى عدد الاستعلامات ثابتاً ولا ينمو مع عدد المهام.',
        );
        $this->assertLessThan(8.0, $elapsedSeconds, 'تجاوزت استجابة القائمة ولوحة المتابعة ميزانية اختبار الحجم.');

        $searchDurations = [];
        foreach (range(1, 20) as $_iteration) {
            $searchStartedAt = microtime(true);
            $this->getJson(route('search', ['q' => 'Volume task 9999']))
                ->assertOk()
                ->assertJsonPath('meta.total', 1);
            $searchDurations[] = microtime(true) - $searchStartedAt;
        }

        sort($searchDurations);
        $p95Seconds = $searchDurations[18];
        $this->assertLessThanOrEqual(0.5, $p95Seconds, 'تجاوز بحث 10,000 مهمة هدف p95 البالغ 500ms.');
    }

    public function test_portfolio_metrics_query_count_does_not_grow_per_project(): void
    {
        $manager = $this->makeUser('project_manager');
        $status = $this->makeStatus('project', 'portfolio-volume', 'in_progress');
        $this->makeProject($manager, $status);

        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'pragma')) {
                $queryCount++;
            }
        });

        $this->actingAs($manager)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('projects.data', 1));
        $baseline = $queryCount;

        foreach (range(2, 20) as $_project) {
            $this->makeProject($manager, $status);
        }

        $queryCount = 0;
        $this->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('projects.data', 20));

        $this->assertLessThanOrEqual(
            $baseline + 2,
            $queryCount,
            'يجب تجميع مؤشرات المحفظة في SQL بدل إضافة استعلامات لكل مشروع.',
        );
    }

    private function insertTasks(
        int $projectId,
        int $statusId,
        int $assigneeId,
        int $first,
        int $last,
        string $timestamp,
    ): void {
        for ($chunkStart = $first; $chunkStart <= $last; $chunkStart += 500) {
            $chunkEnd = min($chunkStart + 499, $last);
            $rows = [];

            for ($sequence = $chunkStart; $sequence <= $chunkEnd; $sequence++) {
                $rows[] = [
                    'project_id' => $projectId,
                    'code' => sprintf('VOL-%05d', $sequence),
                    'title' => "Volume task {$sequence}",
                    'status_id' => $statusId,
                    'priority' => 'medium',
                    'assignee_id' => $assigneeId,
                    'assigned_at' => '2026-08-10 08:00:00',
                    'start_at' => '2026-08-10 08:00:00',
                    'due_at' => '2026-08-12 15:00:00',
                    'lock_version' => 1,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            DB::table('tasks')->insert($rows);
        }
    }
}
