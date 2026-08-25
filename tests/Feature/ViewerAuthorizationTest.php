<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ViewerAuthorizationTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_global_viewer_cannot_be_assigned_as_project_manager_or_mutating_member(): void
    {
        $manager = $this->makeUser('project_manager');
        $viewer = $this->makeUser('viewer');
        $status = $this->makeStatus('project', 'viewer-guard', 'in_progress');

        $this->actingAs($manager)->post(route('projects.store'), [
            'code' => 'VIEWER-MANAGER',
            'name' => 'Viewer manager attempt',
            'manager_id' => $viewer->id,
            'status_id' => $status->id,
            'priority' => 'medium',
            'members' => [['id' => $viewer->id, 'role' => 'manager']],
        ])->assertSessionHasErrors(['manager_id', 'members.0.role']);

        $this->assertDatabaseMissing('projects', ['code' => 'VIEWER-MANAGER']);

        $project = $this->makeProject($manager, $status);
        $this->actingAs($manager)->put(route('projects.update', $project), [
            'code' => $project->code,
            'name' => $project->name,
            'manager_id' => $manager->id,
            'status_id' => $status->id,
            'priority' => 'medium',
            'members' => [['id' => $viewer->id, 'role' => 'member']],
            'lock_version' => $project->lock_version,
        ])->assertSessionHasErrors('members.0.role');
    }

    public function test_global_viewer_remains_read_only_even_with_corrupt_legacy_assignments(): void
    {
        $manager = $this->makeUser('project_manager');
        $viewer = $this->makeUser('viewer');
        $project = $this->makeProject($manager);
        $project->update(['manager_id' => $viewer->id]);
        $project->members()->attach($viewer, ['project_role' => 'manager', 'status' => 'active']);
        $taskStatus = $this->makeStatus('task', 'viewer-task', 'in_progress');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-VIEWER',
            'title' => 'Legacy viewer task',
            'status_id' => $taskStatus->id,
            'priority' => 'medium',
            'assignee_id' => $viewer->id,
            'start_at' => now(),
            'due_at' => now()->addDay(),
            'lock_version' => 1,
        ]);

        $project->refresh();
        $this->assertTrue($viewer->can('view', $project));
        $this->assertFalse($viewer->can('update', $project));
        $this->assertFalse($viewer->can('archive', $project));
        $this->assertFalse($viewer->can('uploadFile', $project));
        $this->assertTrue($viewer->can('view', $task));
        $this->assertFalse($viewer->can('update', $task));
        $this->assertFalse($viewer->can('updateStatus', $task));
    }

    public function test_viewer_cannot_be_assigned_to_a_task(): void
    {
        $manager = $this->makeUser('project_manager');
        $viewer = $this->makeUser('viewer');
        $project = $this->makeProject($manager);
        $project->members()->attach($viewer, ['project_role' => 'viewer', 'status' => 'active']);
        $status = $this->makeStatus('task', 'viewer-assignee', 'in_progress');

        $this->actingAs($manager)->post(route('tasks.store'), [
            'project_id' => $project->id,
            'title' => 'Viewer assignment attempt',
            'status_id' => $status->id,
            'priority' => 'medium',
            'assignee_id' => $viewer->id,
            'start_at' => now()->toDateTimeString(),
            'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertSessionHasErrors('assignee_id');
    }

    public function test_audit_command_repairs_viewer_project_assignments_with_an_activity_record(): void
    {
        $admin = $this->makeUser('admin');
        $viewer = $this->makeUser('viewer');
        $project = $this->makeProject($viewer);

        $this->artisan('project-desk:audit-viewer-assignments', [
            '--apply' => true,
            '--actor' => $admin->email,
        ])->assertSuccessful();

        $project->refresh();
        $this->assertNull($project->manager_id);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'project_role' => 'viewer',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'security.viewer_assignment_repaired',
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'actor_id' => $admin->id,
        ]);
    }
}
