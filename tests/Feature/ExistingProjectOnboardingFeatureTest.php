<?php

namespace Tests\Feature;

use App\Models\ProjectOnboardingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ExistingProjectOnboardingFeatureTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_existing_project_is_created_atomically_with_an_immutable_snapshot(): void
    {
        $manager = $this->makeUser('project_manager');
        $projectStatus = $this->makeStatus('project', 'onboarding-active', 'in_progress');
        $response = $this->actingAs($manager)->post(route('projects.existing.store'), [
            'project' => [
                'code' => 'LEGACY-001', 'name' => 'مشروع قائم', 'manager_id' => $manager->id,
                'status_id' => $projectStatus->id, 'priority' => 'high', 'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
            ],
            'transitioned_at' => now()->toDateTimeString(),
            'members' => [['id' => $manager->id, 'role' => 'manager']],
            'phases' => [[
                'title' => 'مرحلة تاريخية', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay(),
                'status' => 'completed', 'weight_percent' => 40, 'completion_criteria' => 'اعتماد تاريخي', 'milestones' => [],
            ], [
                'title' => 'مرحلة حالية', 'starts_at' => now(), 'ends_at' => now()->addMonth(),
                'status' => 'in_progress', 'weight_percent' => 60, 'milestones' => [],
            ]],
            'tasks' => [], 'risks' => [], 'issues' => [],
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('projects', ['code' => 'LEGACY-001', 'entry_mode' => 'existing', 'progress_mode' => 'phases']);
        $snapshot = ProjectOnboardingSnapshot::query()->firstOrFail();
        $this->assertSame(hash('sha256', json_encode($snapshot->snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $snapshot->snapshot_hash);

        $this->expectException(LogicException::class);
        $snapshot->update(['snapshot_hash' => str_repeat('0', 64)]);
    }

    public function test_any_invalid_phase_rolls_back_the_whole_existing_project(): void
    {
        $manager = $this->makeUser('project_manager');
        $status = $this->makeStatus('project', 'rollback-active', 'in_progress');
        $this->actingAs($manager)->post(route('projects.existing.store'), [
            'project' => ['code' => 'ROLLBACK-1', 'name' => 'Rollback', 'status_id' => $status->id, 'priority' => 'medium', 'start_date' => now()->toDateString()],
            'transitioned_at' => now()->toDateTimeString(),
            'phases' => [['title' => 'خاطئة', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => 'planned', 'weight_percent' => 90, 'milestones' => []]],
        ])->assertSessionHasErrors('phases');
        $this->assertDatabaseMissing('projects', ['code' => 'ROLLBACK-1']);
    }

    public function test_existing_project_rejects_a_global_viewer_as_manager(): void
    {
        $manager = $this->makeUser('project_manager');
        $viewer = $this->makeUser('viewer');
        $status = $this->makeStatus('project', 'viewer-onboarding', 'in_progress');

        $this->actingAs($manager)->post(route('projects.existing.store'), [
            'project' => [
                'code' => 'LEGACY-VIEWER',
                'name' => 'Invalid viewer manager',
                'manager_id' => $viewer->id,
                'status_id' => $status->id,
                'priority' => 'medium',
                'start_date' => now()->subDay()->toDateString(),
            ],
            'transitioned_at' => now()->toDateTimeString(),
            'members' => [['id' => $viewer->id, 'role' => 'manager']],
            'phases' => [[
                'title' => 'Current',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDay(),
                'status' => 'in_progress',
                'weight_percent' => 100,
                'milestones' => [],
            ]],
        ])->assertSessionHasErrors(['project.manager_id', 'members.0.role']);

        $this->assertDatabaseMissing('projects', ['code' => 'LEGACY-VIEWER']);
    }
}
