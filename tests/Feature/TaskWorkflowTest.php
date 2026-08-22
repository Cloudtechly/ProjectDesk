<?php

namespace Tests\Feature;

use App\Models\Requirement;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_task_requires_a_valid_start_and_end_but_not_an_assignee(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $taskStatus = $this->makeStatus('task', 'new', 'open');

        $payload = [
            'project_id' => $project->id,
            'title' => 'مهمة غير مسندة',
            'status_id' => $taskStatus->id,
            'priority' => 'medium',
            'assignee_id' => null,
            'start_at' => '2026-08-12 09:00:00',
            'due_at' => '2026-08-13 17:00:00',
            'requirement_ids' => [],
        ];

        $response = $this->actingAs($manager)->post(route('tasks.store'), $payload);
        $response->assertRedirect(route('tasks.index'));

        $task = Task::query()->firstOrFail();
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->assigned_at);
        $this->assertSame(0, $task->assignmentEvents()->count());
        $this->assertSame('2026-08-12 07:00:00', $task->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-13 15:00:00', $task->due_at->format('Y-m-d H:i:s'));
    }

    public function test_task_rejects_missing_or_reversed_schedule(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $taskStatus = $this->makeStatus('task', 'new', 'open');

        $this->actingAs($manager)->from(route('tasks.create'))->post(route('tasks.store'), [
            'project_id' => $project->id,
            'title' => 'تواريخ خاطئة',
            'status_id' => $taskStatus->id,
            'priority' => 'high',
            'start_at' => '2026-08-14 10:00:00',
            'due_at' => '2026-08-13 10:00:00',
        ])->assertSessionHasErrors('due_at');

        $this->actingAs($manager)->from(route('tasks.create'))->post(route('tasks.store'), [
            'project_id' => $project->id,
            'title' => 'نهاية ناقصة',
            'status_id' => $taskStatus->id,
            'priority' => 'high',
            'start_at' => '2026-08-14 10:00:00',
        ])->assertSessionHasErrors('due_at');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_project_viewer_cannot_be_assigned_a_task(): void
    {
        $manager = $this->makeUser('project_manager');
        $viewer = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($viewer, ['project_role' => 'viewer', 'status' => 'active']);
        $taskStatus = $this->makeStatus('task', 'viewer-assignee-open', 'open');

        $this->actingAs($manager)->post(route('tasks.store'), [
            'project_id' => $project->id,
            'title' => 'مهمة لا يجوز إسنادها لمشاهد',
            'status_id' => $taskStatus->id,
            'priority' => 'medium',
            'assignee_id' => $viewer->id,
            'start_at' => '2026-08-12 09:00:00',
            'due_at' => '2026-08-13 17:00:00',
        ])->assertSessionHasErrors('assignee_id');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_reassignment_is_recorded_on_save_without_changing_schedule(): void
    {
        $manager = $this->makeUser('project_manager');
        $first = $this->makeUser('member');
        $second = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($first, ['project_role' => 'member', 'status' => 'active']);
        $project->members()->attach($second, ['project_role' => 'member', 'status' => 'active']);
        $taskStatus = $this->makeStatus('task', 'in_progress', 'in_progress');

        $this->actingAs($manager)->post(route('tasks.store'), [
            'project_id' => $project->id,
            'title' => 'إعادة إسناد',
            'status_id' => $taskStatus->id,
            'priority' => 'high',
            'assignee_id' => $first->id,
            'start_at' => '2026-08-12 09:00:00',
            'due_at' => '2026-08-16 17:00:00',
            'requirement_ids' => [],
        ]);
        $task = Task::query()->firstOrFail();
        $initialStart = $task->start_at->toIso8601String();
        $initialDue = $task->due_at->toIso8601String();

        $this->actingAs($manager)->put(route('tasks.update', $task), [
            'project_id' => $project->id,
            'title' => $task->title,
            'status_id' => $taskStatus->id,
            'priority' => 'high',
            'assignee_id' => $second->id,
            'start_at' => '2026-08-12 09:00:00',
            'due_at' => '2026-08-16 17:00:00',
            'requirement_ids' => [],
            'assignment_note' => 'توزيع الحمل',
            'lock_version' => $task->lock_version,
        ])->assertRedirect();

        $task->refresh();
        $this->assertSame($second->id, $task->assignee_id);
        $this->assertSame($initialStart, $task->start_at->toIso8601String());
        $this->assertSame($initialDue, $task->due_at->toIso8601String());
        $this->assertDatabaseHas('task_assignment_events', [
            'task_id' => $task->id,
            'from_user_id' => $first->id,
            'to_user_id' => $second->id,
            'note' => 'توزيع الحمل',
        ]);
    }

    public function test_assignee_can_change_status_and_completion_is_derived(): void
    {
        $manager = $this->makeUser('project_manager');
        $assignee = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($assignee, ['project_role' => 'member', 'status' => 'active']);
        $open = $this->makeStatus('task', 'open', 'open');
        $done = $this->makeStatus('task', 'done', 'done');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-STATUS',
            'title' => 'اختبار الحالة',
            'status_id' => $open->id,
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
            'assigned_at' => now(),
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        $task->refresh();

        $this->actingAs($assignee)->patch(route('tasks.status.update', $task), [
            'status_id' => $done->id,
            'lock_version' => $task->lock_version,
        ])->assertRedirect();

        $task->refresh();
        $this->assertSame($done->id, $task->status_id);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(2, $task->lock_version);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.status_changed', 'subject_id' => $task->id]);
    }

    public function test_full_task_update_requires_the_clients_lock_version(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $status = $this->makeStatus('task', 'lock-required', 'open');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-LOCK',
            'title' => 'مهمة محمية بالتزامن',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($manager)->put(route('tasks.update', $task), [
            'project_id' => $project->id,
            'title' => 'محاولة دون نسخة',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => now()->toDateTimeString(),
            'due_at' => now()->addDay()->toDateTimeString(),
            'requirement_ids' => [],
        ])->assertSessionHasErrors('lock_version');

        $this->assertSame('مهمة محمية بالتزامن', $task->fresh()->title);
    }

    public function test_assignee_cannot_move_task_to_an_unrelated_project(): void
    {
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $assignee = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $otherProject = $this->makeProject($otherManager, $project->status);
        $project->members()->attach($assignee, ['project_role' => 'member', 'status' => 'active']);
        $status = $this->makeStatus('task', 'secure-open', 'open');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-SECURE',
            'title' => 'مهمة محمية',
            'status_id' => $status->id,
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
            'assigned_at' => now(),
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        $task->refresh();

        $this->actingAs($assignee)->put(route('tasks.update', $task), [
            'project_id' => $otherProject->id,
            'title' => $task->title,
            'status_id' => $status->id,
            'priority' => $task->priority,
            'start_at' => $task->start_at->toDateTimeString(),
            'due_at' => $task->due_at->toDateTimeString(),
            'lock_version' => $task->lock_version,
        ])->assertForbidden();

        $this->assertSame($project->id, $task->fresh()->project_id);
    }

    public function test_assignee_can_update_status_but_cannot_edit_full_task(): void
    {
        $manager = $this->makeUser('project_manager');
        $assignee = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($assignee, ['project_role' => 'member', 'status' => 'active']);
        $open = $this->makeStatus('task', 'member-open', 'open');
        $done = $this->makeStatus('task', 'member-done', 'done');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-MEMBER',
            'title' => 'عنوان أصلي',
            'status_id' => $open->id,
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
            'assigned_at' => now(),
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        $task->refresh();

        $this->actingAs($assignee)->put(route('tasks.update', $task), [
            'project_id' => $project->id,
            'title' => 'عنوان مزور',
            'status_id' => $done->id,
            'priority' => 'critical',
            'start_at' => '2026-08-12 09:00:00',
            'due_at' => '2026-08-20 17:00:00',
            'lock_version' => $task->lock_version,
        ])->assertForbidden();

        $this->actingAs($assignee)->patch(route('tasks.status.update', $task), [
            'status_id' => $done->id,
            'lock_version' => $task->lock_version,
        ])->assertRedirect();
        $this->assertSame('عنوان أصلي', $task->fresh()->title);
        $this->assertSame($done->id, $task->fresh()->status_id);
    }

    public function test_task_list_exposes_capabilities_without_showing_manager_actions_to_assignee(): void
    {
        $manager = $this->makeUser('project_manager');
        $assignee = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($assignee, ['project_role' => 'member', 'status' => 'active']);
        $status = $this->makeStatus('task', 'capability-open', 'open');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-CAPABILITY',
            'title' => 'مهمة الصلاحيات',
            'status_id' => $status->id,
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
            'assigned_at' => now(),
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($assignee)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tasks/index')
                ->where('canCreate', false)
                ->where('tasks.data.0.id', $task->id)
                ->where('tasks.data.0.can_update', false)
                ->where('tasks.data.0.can_update_status', true));
    }

    public function test_admin_cannot_mutate_task_inside_archived_project(): void
    {
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($admin);
        $status = $this->makeStatus('task', 'archived-open', 'open');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-ARCHIVED',
            'title' => 'مهمة مؤرشفة السياق',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        $project->update(['archived_at' => now()]);

        $this->actingAs($admin)->patch(route('tasks.status.update', $task), [
            'status_id' => $status->id,
            'lock_version' => $task->lock_version,
        ])->assertForbidden();
        $this->actingAs($admin)->post(route('tasks.store'), [
            'project_id' => $project->id,
            'title' => 'مهمة جديدة ممنوعة',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => '2026-08-12 09:00:00',
            'due_at' => '2026-08-13 09:00:00',
        ])->assertForbidden();
    }

    public function test_manager_can_archive_list_and_restore_a_task_without_deleting_history(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $status = $this->makeStatus('task', 'archive-cycle', 'open');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-ARCHIVE-CYCLE',
            'title' => 'مهمة محفوظة في الأرشيف',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        $task->refresh();

        $this->actingAs($manager)->post(route('tasks.archive', $task), [
            'lock_version' => $task->lock_version,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertNotNull($task->archived_at);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 0));
        $this->get(route('tasks.index', ['archived' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.data.0.id', $task->id)
                ->where('tasks.data.0.can_update', false)
                ->where('tasks.data.0.can_restore', true));

        $this->post(route('tasks.restore', $task), [
            'lock_version' => $task->lock_version,
        ])->assertRedirect();

        $task->refresh();
        $this->assertNull($task->archived_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.archived', 'subject_id' => $task->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.restored', 'subject_id' => $task->id]);
    }

    public function test_task_index_whitelists_sorting_and_preserves_the_effective_query(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $status = $this->makeStatus('task', 'sort-open', 'open');
        foreach (['ألف', 'ياء'] as $index => $title) {
            Task::query()->create([
                'project_id' => $project->id,
                'code' => "TSK-SORT-{$index}",
                'title' => $title,
                'status_id' => $status->id,
                'priority' => 'medium',
                'start_at' => now(),
                'due_at' => now()->addDays($index + 1),
            ]);
        }

        $this->actingAs($manager)
            ->get(route('tasks.index', ['sort' => 'title', 'direction' => 'desc', 'view' => 'kanban']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'title')
                ->where('filters.direction', 'desc')
                ->where('filters.view', 'kanban')
                ->where('tasks.data.0.title', 'ياء'));

        $this->get(route('tasks.index', ['sort' => 'title; drop table tasks', 'direction' => 'sideways']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'due_at')
                ->where('filters.direction', 'asc'));
    }

    public function test_task_form_props_exclude_viewers_and_expose_active_project_requirements(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $viewer = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $project->members()->attach($viewer, ['project_role' => 'viewer', 'status' => 'active']);
        $requirementStatus = $this->makeStatus('requirement', 'requirement-form-open', 'open');
        $requirement = Requirement::query()->create([
            'project_id' => $project->id,
            'code' => 'REQ-FORM',
            'title' => 'متطلب نموذج المهمة',
            'priority' => 'medium',
            'status_id' => $requirementStatus->id,
        ]);

        $this->actingAs($manager)
            ->get(route('tasks.create', ['project' => $project->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has("projectMembers.{$project->id}", 2)
                ->where(
                    "projectMembers.{$project->id}",
                    fn ($members) => collect($members)->pluck('id')->sort()->values()->all()
                        === collect([$manager->id, $member->id])->sort()->values()->all(),
                )
                ->where("projectRequirements.{$project->id}.0.id", $requirement->id)
                ->where("projectRequirements.{$project->id}.0.code", 'REQ-FORM'));
    }

    public function test_task_index_supports_the_unassigned_drill_down_sentinel(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $status = $this->makeStatus('task', 'unassigned-open', 'open');
        $unassigned = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-UNASSIGNED',
            'title' => 'مهمة غير مسندة',
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-ASSIGNED',
            'title' => 'مهمة مسندة',
            'status_id' => $status->id,
            'priority' => 'medium',
            'assignee_id' => $member->id,
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($manager)
            ->get(route('tasks.index', ['assignee' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.assignee', 'unassigned')
                ->has('tasks.data', 1)
                ->where('tasks.data.0.id', $unassigned->id));
    }
}
