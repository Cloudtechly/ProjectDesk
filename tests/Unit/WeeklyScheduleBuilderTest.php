<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Services\WeeklyScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class WeeklyScheduleBuilderTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_tasks_are_clipped_to_the_selected_sunday_to_saturday_week(): void
    {
        Date::setTestNow('2026-08-12 12:00:00');
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $status = $this->makeStatus('task', 'in_progress', 'in_progress');

        Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-00001',
            'title' => 'مهمة ممتدة',
            'status_id' => $status->id,
            'priority' => 'high',
            'start_at' => '2026-08-08 09:00:00',
            'due_at' => '2026-08-16 17:00:00',
        ]);
        Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-00002',
            'title' => 'مهمة يوم واحد',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => '2026-08-11 09:00:00',
            'due_at' => '2026-08-11 17:00:00',
        ]);

        $schedule = (new WeeklyScheduleBuilder)->build($admin, '2026-08-12');
        $this->assertSame('2026-08-09', $schedule['weekStart']);
        $this->assertSame('2026-08-15', $schedule['weekEnd']);
        $this->assertCount(7, $schedule['days']);
        $bars = $schedule['rows'][0]['bars'];

        $this->assertSame(1, $bars[0]['startColumn']);
        $this->assertSame(7, $bars[0]['span']);
        $this->assertTrue($bars[0]['continuesBefore']);
        $this->assertTrue($bars[0]['continuesAfter']);
        $this->assertSame(3, $bars[1]['startColumn']);
        $this->assertSame(1, $bars[1]['span']);
    }

    public function test_tripoli_week_boundaries_are_compared_against_utc_storage(): void
    {
        Date::setTestNow('2026-08-12 10:00:00');
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $status = $this->makeStatus('task', 'boundary-open', 'open');

        Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-SUNDAY-MIDNIGHT',
            'title' => 'بداية الأحد في طرابلس',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => '2026-08-08 22:00:00',
            'due_at' => '2026-08-08 23:00:00',
        ]);
        Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-NEXT-SUNDAY',
            'title' => 'الأحد التالي',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => '2026-08-15 22:00:00',
            'due_at' => '2026-08-15 23:00:00',
        ]);

        $schedule = (new WeeklyScheduleBuilder)->build($admin, '2026-08-12');
        $bars = collect($schedule['rows'][0]['bars']);

        $this->assertTrue($bars->contains(fn (array $bar): bool => $bar['title'] === 'بداية الأحد في طرابلس'));
        $this->assertFalse($bars->contains(fn (array $bar): bool => $bar['title'] === 'الأحد التالي'));
    }

    public function test_dense_projects_are_capped_to_three_visible_lanes_with_an_exact_hidden_count(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $status = $this->makeStatus('task', 'dense-open', 'open');

        foreach (range(1, 60) as $sequence) {
            Task::query()->create([
                'project_id' => $project->id,
                'code' => sprintf('DENSE-%03d', $sequence),
                'title' => "Dense task {$sequence}",
                'status_id' => $status->id,
                'priority' => 'medium',
                'start_at' => '2026-08-10 09:00:00',
                'due_at' => '2026-08-12 17:00:00',
            ]);
        }

        $row = (new WeeklyScheduleBuilder)->build($admin, '2026-08-12')['rows'][0];

        $this->assertSame(3, $row['laneCount']);
        $this->assertSame(60, $row['totalBarCount']);
        $this->assertSame(57, $row['hiddenCount']);
        $this->assertCount(3, $row['bars']);
    }

    public function test_read_only_members_receive_a_safe_task_list_link_instead_of_the_editor(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $status = $this->makeStatus('task', 'member-open', 'open');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'SAFE-LINK-001',
            'title' => 'Safe member link',
            'status_id' => $status->id,
            'priority' => 'medium',
            'assignee_id' => $member->id,
            'start_at' => '2026-08-10 09:00:00',
            'due_at' => '2026-08-12 17:00:00',
        ]);

        $bar = (new WeeklyScheduleBuilder)->build($member, '2026-08-12')['rows'][0]['bars'][0];

        $this->assertSame(
            route('tasks.index', ['project' => $project->id, 'q' => $task->code], false),
            $bar['href'],
        );
    }
}
