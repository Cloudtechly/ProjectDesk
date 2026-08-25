<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Services\ProjectMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class PhasePlanFeatureTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_weighted_progress_stops_at_99_while_a_required_milestone_is_open(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $done = $this->makeStatus('task', 'phase-done', 'done');

        $response = $this->actingAs($manager)->putJson(route('projects.phase-plan.update', $project), [
            'phases' => [
                [
                    'title' => 'التحليل', 'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(5),
                    'status' => 'in_progress', 'weight_percent' => 60, 'completion_criteria' => 'اعتماد النطاق',
                    'milestones' => [[
                        'title' => 'اعتماد النطاق', 'date' => now()->addDay(), 'status' => 'planned',
                        'is_gate' => true,
                    ]],
                ],
                [
                    'title' => 'التنفيذ', 'starts_at' => now()->addDays(6), 'ends_at' => now()->addDays(20),
                    'status' => 'planned', 'weight_percent' => 40, 'milestones' => [],
                ],
            ],
        ])->assertOk()->assertJsonPath('data.weight_total', 100);

        $phaseId = (int) $response->json('data.phases.0.id');
        Task::query()->create([
            'project_id' => $project->id, 'phase_id' => $phaseId, 'code' => 'TSK-GATE', 'title' => 'تحليل مكتمل',
            'status_id' => $done->id, 'priority' => 'medium', 'start_at' => now()->subDay(),
            'due_at' => now(), 'completed_at' => now(),
        ]);

        $metrics = app(ProjectMetrics::class)->for($project->fresh());
        $this->assertSame(59, $metrics['progress']);
        $this->assertSame(99, $metrics['current_phase']['progress']);
        $this->assertTrue($metrics['current_phase']['awaiting_approval']);
        $this->assertSame('phases', $metrics['progress_mode']);
    }

    public function test_plan_rejects_weights_other_than_one_hundred_and_completed_phase_with_open_gate(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $base = [
            'title' => 'مرحلة', 'starts_at' => now(), 'ends_at' => now()->addDay(),
            'status' => 'planned', 'weight_percent' => 80, 'milestones' => [],
        ];
        $this->actingAs($manager)->putJson(route('projects.phase-plan.update', $project), ['phases' => [$base]])
            ->assertUnprocessable()->assertJsonValidationErrors('phases');

        $this->putJson(route('projects.phase-plan.update', $project), ['phases' => [[
            ...$base, 'status' => 'completed', 'weight_percent' => 100,
            'milestones' => [[
                'title' => 'بوابة', 'date' => now(), 'status' => 'planned', 'is_gate' => true,
            ]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('phases');
        $this->assertDatabaseCount('timeline_entries', 0);
    }
}
