<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Risk;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_week_navigation_moves_exactly_seven_calendar_days(): void
    {
        Date::setTestNow('2026-08-12 12:00:00');
        $user = User::factory()->create(['global_role' => 'admin', 'status' => 'active']);

        $this->actingAs($user)
            ->get(route('dashboard', ['week' => '2026-08-09', 'direction' => 'next']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('currentWeek', '2026-08-16')
                ->where('weeklySchedule.weekStart', '2026-08-16')
                ->where('weeklySchedule.weekEnd', '2026-08-22'));
    }

    public function test_an_arbitrary_selected_date_is_kept_in_the_url_and_resolves_to_its_sunday_week(): void
    {
        $user = User::factory()->create(['global_role' => 'admin', 'status' => 'active']);

        $this->actingAs($user)
            ->get(route('dashboard', ['week' => '2026-10-14']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('selectedDate', '2026-10-14')
                ->where('currentWeek', '2026-10-11')
                ->where('weeklySchedule.weekStart', '2026-10-11')
                ->where('weeklySchedule.weekEnd', '2026-10-17'));
    }

    public function test_quick_task_action_is_shared_only_with_authorized_users(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('canCreateTask', true));

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('canCreateTask', false));
    }

    public function test_active_project_list_excludes_completed_and_cancelled_projects(): void
    {
        $manager = $this->makeUser('project_manager');
        $active = $this->makeProject($manager, $this->makeStatus('project', 'dash-active', 'in_progress'));
        $this->makeProject($manager, $this->makeStatus('project', 'dash-done', 'done'));
        $this->makeProject($manager, $this->makeStatus('project', 'dash-cancelled', 'cancelled'));

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects', 1)
                ->where('projects.0.id', $active->id)
                ->where('summary.activeProjects', 1));
    }

    public function test_attention_and_governance_lists_include_only_actionable_records(): void
    {
        Date::setTestNow('2026-08-12 10:00:00');
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $open = $this->makeStatus('task', 'dashboard-open', 'open');
        $done = $this->makeStatus('task', 'dashboard-done', 'done');

        $overdue = $this->makeTask($project->id, $open->id, 'متأخرة', now()->subHour(), $manager->id);
        $soon = $this->makeTask($project->id, $open->id, 'قريبة', now()->addDays(7), $manager->id);
        $this->makeTask($project->id, $open->id, 'بعيدة', now()->addDays(8), $manager->id);
        $this->makeTask($project->id, $done->id, 'منجزة', now()->addDay(), $manager->id);

        $highRisk = Risk::query()->create([
            'project_id' => $project->id,
            'title' => 'مخاطرة حرجة',
            'probability' => 4,
            'impact' => 4,
            'status' => 'open',
        ]);
        Risk::query()->create([
            'project_id' => $project->id,
            'title' => 'مخاطرة منخفضة',
            'probability' => 2,
            'impact' => 2,
            'status' => 'open',
        ]);
        $importantIssue = Issue::query()->create([
            'project_id' => $project->id,
            'title' => 'مشكلة مهمة',
            'severity' => 'high',
            'status' => 'in_progress',
        ]);
        Issue::query()->create([
            'project_id' => $project->id,
            'title' => 'مشكلة منخفضة',
            'severity' => 'low',
            'status' => 'open',
        ]);
        Issue::query()->create([
            'project_id' => $project->id,
            'title' => 'مشكلة محلولة',
            'severity' => 'critical',
            'status' => 'resolved',
            'resolution' => 'تم الحل',
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 2)
                ->where('tasks.0.id', $overdue->id)
                ->where('tasks.1.id', $soon->id)
                ->has('risks', 1)
                ->where('risks.0.id', $highRisk->id)
                ->has('issues', 1)
                ->where('issues.0.id', $importantIssue->id)
                ->where('workload.0.href', route('tasks.index', ['assignee' => $manager->id], false)));
    }

    public function test_dashboard_distributions_are_exact_and_exclude_unauthorized_projects(): void
    {
        Date::setTestNow('2026-08-12 10:00:00');
        $manager = $this->makeUser('project_manager');
        $outsider = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $hiddenProject = $this->makeProject($outsider, $project->status);
        $open = $this->makeStatus('task', 'chart-open', 'open');
        $done = $this->makeStatus('task', 'chart-done', 'done');

        $this->makeTask($project->id, $open->id, 'مرئية مفتوحة', now()->addDay(), $manager->id);
        $this->makeTask($project->id, $done->id, 'مرئية منجزة', now()->subDay(), $manager->id);
        $this->makeTask($hiddenProject->id, $open->id, 'مخفية', now()->subDay(), $outsider->id);

        Risk::query()->create([
            'project_id' => $project->id,
            'title' => 'خطر مرئي أول',
            'probability' => 5,
            'impact' => 4,
            'status' => 'open',
        ]);
        Risk::query()->create([
            'project_id' => $project->id,
            'title' => 'خطر مرئي ثان',
            'probability' => 4,
            'impact' => 4,
            'status' => 'open',
        ]);
        Risk::query()->create([
            'project_id' => $hiddenProject->id,
            'title' => 'خطر مخفي',
            'probability' => 5,
            'impact' => 5,
            'status' => 'open',
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.highRisks', 1)
                ->has('taskStatusDistribution', 2)
                ->where('taskStatusDistribution.0.count', 1)
                ->where('taskStatusDistribution.1.count', 1)
                ->has('projectHealthDistribution', 3)
                ->where('projectHealthDistribution.0.key', 'danger')
                ->where('projectHealthDistribution.0.count', 1)
                ->where('projectHealthDistribution.1.count', 0)
                ->where('projectHealthDistribution.2.count', 0));
    }

    private function makeTask(
        int $projectId,
        int $statusId,
        string $title,
        CarbonInterface $dueAt,
        ?int $assigneeId = null,
    ): Task {
        return Task::query()->create([
            'project_id' => $projectId,
            'code' => 'DSH-'.fake()->unique()->numerify('#####'),
            'title' => $title,
            'status_id' => $statusId,
            'priority' => 'medium',
            'assignee_id' => $assigneeId,
            'start_at' => now()->subDay(),
            'due_at' => $dueAt,
        ]);
    }
}
