<?php

namespace Tests\Feature;

use App\Models\Risk;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class DashboardDrilldownTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_active_projects_metric_opens_the_matching_filtered_list(): void
    {
        $manager = $this->makeUser('project_manager');
        $activeStatus = $this->makeStatus('project', 'active-work', 'in_progress');
        $doneStatus = $this->makeStatus('project', 'closed-work', 'done');
        $active = $this->makeProject($manager, $activeStatus);
        $this->makeProject($manager, $doneStatus);

        $this->actingAs($manager)
            ->get(route('projects.index', ['activity' => 'active']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->where('filters.activity', 'active')
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $active->id));
    }

    public function test_high_risk_metric_opens_only_projects_with_open_high_risks(): void
    {
        $manager = $this->makeUser('project_manager');
        $highRiskProject = $this->makeProject($manager);
        $lowRiskProject = $this->makeProject($manager, $highRiskProject->status);
        $closedRiskProject = $this->makeProject($manager, $highRiskProject->status);
        $archivedRiskProject = $this->makeProject($manager, $highRiskProject->status);

        Risk::query()->create([
            'project_id' => $highRiskProject->id,
            'title' => 'مخاطر مرتفعة',
            'probability' => 4,
            'impact' => 4,
            'status' => 'open',
        ]);
        Risk::query()->create([
            'project_id' => $lowRiskProject->id,
            'title' => 'مخاطر منخفضة',
            'probability' => 2,
            'impact' => 3,
            'status' => 'open',
        ]);
        Risk::query()->create([
            'project_id' => $closedRiskProject->id,
            'title' => 'مخاطر مغلقة',
            'probability' => 5,
            'impact' => 5,
            'status' => 'closed',
        ]);
        Risk::query()->create([
            'project_id' => $archivedRiskProject->id,
            'title' => 'Archived high risk',
            'probability' => 5,
            'impact' => 5,
            'status' => 'open',
            'archived_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get(route('projects.index', ['risk' => 'high']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->where('filters.risk', 'high')
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $highRiskProject->id));
    }

    public function test_due_soon_metric_opens_only_open_tasks_due_in_the_next_seven_days(): void
    {
        Date::setTestNow('2026-08-12 10:00:00');
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $open = $this->makeStatus('task', 'metric-open', 'open');
        $done = $this->makeStatus('task', 'metric-done', 'done');

        $expected = $this->makeTask($project->id, $open->id, 'قريبة', now()->addDays(2));
        $this->makeTask($project->id, $open->id, 'بعيدة', now()->addDays(8));
        $this->makeTask($project->id, $open->id, 'متأخرة', now()->subHour());
        $this->makeTask($project->id, $done->id, 'منجزة قريبة', now()->addDay());

        $this->actingAs($manager)
            ->get(route('tasks.index', ['due' => 'soon']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('tasks/index')
                ->where('filters.due', 'soon')
                ->has('tasks.data', 1)
                ->where('tasks.data.0.id', $expected->id));
    }

    public function test_project_health_drilldown_uses_the_same_derived_health_formula(): void
    {
        Date::setTestNow('2026-08-12 10:00:00');
        $manager = $this->makeUser('project_manager');
        $status = $this->makeStatus('project', 'health-active', 'in_progress');
        $danger = $this->makeProject($manager, $status);
        $attention = $this->makeProject($manager, $status);
        $healthy = $this->makeProject($manager, $status);
        $open = $this->makeStatus('task', 'health-open', 'open');

        $this->makeTask($danger->id, $open->id, 'متأخرة', now()->subHour());
        $this->makeTask($attention->id, $open->id, 'مفتوحة', now()->addDay());

        $this->actingAs($manager)
            ->get(route('projects.index', ['activity' => 'active', 'health' => 'danger']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->where('filters.health', 'danger')
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $danger->id));

        $this->actingAs($manager)
            ->get(route('projects.index', ['activity' => 'active', 'health' => 'attention']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $attention->id));

        $this->actingAs($manager)
            ->get(route('projects.index', ['activity' => 'active', 'health' => 'healthy']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.id', $healthy->id));
    }

    public function test_unassigned_workload_row_links_to_the_matching_task_filter(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $open = $this->makeStatus('task', 'workload-open', 'open');
        $task = $this->makeTask($project->id, $open->id, 'بلا مسؤول', now()->addDay());

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('workload.0.id', 'unassigned')
                ->where('workload.0.href', route('tasks.index', ['assignee' => 'unassigned'], false)));

        $this->actingAs($manager)
            ->get(route('tasks.index', ['assignee' => 'unassigned']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.id', $task->id));
    }

    private function makeTask(int $projectId, int $statusId, string $title, CarbonInterface $dueAt): Task
    {
        return Task::query()->create([
            'project_id' => $projectId,
            'code' => 'TSK-'.fake()->unique()->numerify('#####'),
            'title' => $title,
            'status_id' => $statusId,
            'priority' => 'medium',
            'start_at' => now()->subDay(),
            'due_at' => $dueAt,
        ]);
    }
}
