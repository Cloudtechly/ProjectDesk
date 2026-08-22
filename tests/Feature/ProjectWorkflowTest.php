<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ProjectWorkflowTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_project_manager_can_create_a_project_without_tasks(): void
    {
        $manager = $this->makeUser('project_manager');
        $status = $this->makeStatus('project', 'planning', 'open');
        $client = Client::query()->create([
            'created_by' => $manager->id,
            'code' => 'CL-001',
            'name' => 'عميل تجريبي',
            'status' => 'active',
        ]);

        $response = $this->actingAs($manager)->post(route('projects.store'), [
            'code' => 'PRJ-001',
            'name' => 'مشروع بلا مهام',
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status_id' => $status->id,
            'priority' => 'high',
            'start_date' => '2026-08-12',
            'end_date' => '2026-09-12',
            'member_ids' => [],
        ]);

        $project = Project::query()->where('code', 'PRJ-001')->firstOrFail();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame(0, $project->tasks()->count());
        $this->assertTrue($project->members()->whereKey($manager->id)->exists());
        $this->assertDatabaseHas('activity_logs', ['action' => 'project.created', 'subject_id' => $project->id]);
    }

    public function test_user_outside_project_cannot_open_it(): void
    {
        $manager = $this->makeUser('project_manager');
        $outsider = $this->makeUser('member');
        $project = $this->makeProject($manager);

        $this->actingAs($outsider)->get(route('projects.show', $project))->assertForbidden();
    }

    public function test_project_manager_cannot_forge_another_managers_client_on_create_or_update(): void
    {
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $status = $this->makeStatus('project', 'client-scope', 'open');
        $ownClient = Client::query()->create([
            'created_by' => $manager->id,
            'code' => 'CL-OWN',
            'name' => 'العميل المصرح',
            'status' => 'active',
        ]);
        $foreignClient = Client::query()->create([
            'created_by' => $otherManager->id,
            'code' => 'CL-FOREIGN',
            'name' => 'عميل غير مصرح',
            'status' => 'active',
        ]);

        $this->actingAs($manager)->post(route('projects.store'), [
            'code' => 'PRJ-FORGED',
            'name' => 'مشروع مزور',
            'client_id' => $foreignClient->id,
            'manager_id' => $manager->id,
            'status_id' => $status->id,
            'priority' => 'medium',
            'member_ids' => [],
        ])->assertSessionHasErrors('client_id');

        $project = $this->makeProject($manager, $status);
        $project->update(['client_id' => $ownClient->id]);
        $this->actingAs($manager)->put(route('projects.update', $project), [
            'code' => $project->code,
            'name' => $project->name,
            'client_id' => $foreignClient->id,
            'manager_id' => $manager->id,
            'status_id' => $status->id,
            'priority' => 'medium',
            'member_ids' => [],
            'lock_version' => $project->lock_version,
        ])->assertSessionHasErrors('client_id');

        $this->assertSame($ownClient->id, $project->fresh()->client_id);
    }

    public function test_project_manager_can_update_project_members_with_optimistic_locking(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);

        $response = $this->actingAs($manager)->put(route('projects.update', $project), [
            'code' => $project->code,
            'name' => 'الاسم المحدث',
            'client_id' => $project->client_id,
            'manager_id' => $manager->id,
            'status_id' => $project->status_id,
            'priority' => 'high',
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'member_ids' => [$member->id],
            'lock_version' => $project->lock_version,
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $project->refresh();
        $this->assertSame('الاسم المحدث', $project->name);
        $this->assertSame(2, $project->lock_version);
        $this->assertTrue($project->members()->whereKey($member->id)->exists());

        $this->actingAs($manager)->put(route('projects.update', $project), [
            'code' => $project->code,
            'name' => 'تعديل متعارض',
            'client_id' => $project->client_id,
            'manager_id' => $manager->id,
            'status_id' => $project->status_id,
            'priority' => 'high',
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'member_ids' => [$member->id],
            'lock_version' => 1,
        ])->assertConflict();
    }

    public function test_project_membership_roles_are_saved_atomically_and_enforce_viewer_permissions(): void
    {
        $manager = $this->makeUser('project_manager');
        $coManager = $this->makeUser('member');
        $member = $this->makeUser('member');
        $viewer = $this->makeUser('member');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)->put(route('projects.update', $project), [
            'code' => $project->code,
            'name' => $project->name,
            'client_id' => $project->client_id,
            'manager_id' => $manager->id,
            'status_id' => $project->status_id,
            'priority' => 'high',
            'members' => [
                ['id' => $coManager->id, 'role' => 'manager'],
                ['id' => $member->id, 'role' => 'member'],
                ['id' => $viewer->id, 'role' => 'viewer'],
            ],
            'lock_version' => $project->lock_version,
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $coManager->id,
            'project_role' => 'manager',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'project_role' => 'member',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $viewer->id,
            'project_role' => 'viewer',
            'status' => 'active',
        ]);
        $this->assertTrue($coManager->can('update', $project));
        $this->assertFalse($member->can('update', $project));
        $this->assertTrue($viewer->can('view', $project));
        $this->assertFalse($viewer->can('update', $project));
        $this->assertFalse($viewer->can('uploadFile', $project));

        $after = DB::table('activity_logs')
            ->where('subject_type', Project::class)
            ->where('subject_id', $project->id)
            ->where('action', 'project.updated')
            ->latest('id')
            ->value('after');
        $decoded = json_decode((string) $after, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('viewer', collect($decoded['members'])->firstWhere('id', $viewer->id)['role']);
    }

    public function test_project_member_roles_reject_duplicates_and_unknown_roles_without_partial_write(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)->put(route('projects.update', $project), [
            'code' => $project->code,
            'name' => 'يجب ألا يحفظ',
            'client_id' => $project->client_id,
            'manager_id' => $manager->id,
            'status_id' => $project->status_id,
            'priority' => $project->priority,
            'members' => [
                ['id' => $member->id, 'role' => 'member'],
                ['id' => $member->id, 'role' => 'owner'],
            ],
            'lock_version' => $project->lock_version,
        ])->assertSessionHasErrors(['members.1.id', 'members.1.role']);

        $this->assertNotSame('يجب ألا يحفظ', $project->fresh()->name);
        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_archived_project_can_be_listed_and_restored_without_data_loss(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)
            ->post(route('projects.archive', $project))
            ->assertRedirect(route('projects.index'));

        $this->assertNotNull($project->fresh()->archived_at);
        $this->actingAs($manager)
            ->get(route('projects.index', ['scope' => 'archived']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/index')
                ->where('filters.scope', 'archived')
                ->where('projects.data.0.id', $project->id)
                ->where('projects.data.0.canRestore', true));

        $this->actingAs($manager)
            ->post(route('projects.restore', $project))
            ->assertRedirect(route('projects.show', $project));

        $this->assertNull($project->fresh()->archived_at);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project.restored',
            'subject_id' => $project->id,
        ]);
    }

    public function test_inactive_project_manager_membership_cannot_restore_an_archived_project(): void
    {
        $manager = $this->makeUser('project_manager');
        $admin = $this->makeUser('admin');
        $project = $this->makeProject($manager);
        $project->update(['archived_at' => now()]);
        $project->members()->updateExistingPivot($manager->id, ['status' => 'inactive']);

        $this->assertFalse($manager->can('restore', $project->fresh()));
        $this->assertTrue($admin->can('restore', $project->fresh()));

        $this->actingAs($manager)
            ->post(route('projects.restore', $project))
            ->assertForbidden();

        $this->assertNotNull($project->fresh()->archived_at);
    }
}
