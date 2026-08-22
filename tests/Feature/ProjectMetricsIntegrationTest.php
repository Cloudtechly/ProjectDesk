<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TimelineEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ProjectMetricsIntegrationTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_project_list_and_pdf_audit_use_the_same_project_metrics(): void
    {
        Date::setTestNow('2026-08-12 10:00:00');
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $done = $this->makeStatus('task', 'integration-done', 'done');
        $open = $this->makeStatus('task', 'integration-open', 'open');
        $cancelled = $this->makeStatus('task', 'integration-cancelled', 'cancelled');

        foreach ([[$done->id, 'منجزة'], [$open->id, 'مفتوحة'], [$cancelled->id, 'ملغاة']] as [$statusId, $title]) {
            Task::query()->create([
                'project_id' => $project->id,
                'code' => 'INT-'.fake()->unique()->numerify('#####'),
                'title' => $title,
                'status_id' => $statusId,
                'priority' => 'medium',
                'start_at' => now()->subDay(),
                'due_at' => now()->addDay(),
            ]);
        }
        $nextStage = TimelineEntry::query()->create([
            'project_id' => $project->id,
            'kind' => 'milestone',
            'title' => 'اعتماد التصميم',
            'starts_at' => now()->addDays(2),
            'status' => 'planned',
        ]);

        $this->actingAs($manager)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.data.0.progress', 50)
                ->where('projects.data.0.health', 'attention')
                ->where('projects.data.0.priority', 'medium')
                ->where('projects.data.0.nextStage.id', $nextStage->id)
                ->where('projects.data.0.nextStage.title', 'اعتماد التصميم'));

        $this->get(route('projects.summary.pdf', $project))->assertOk();
        $audit = DB::table('activity_logs')
            ->where('action', 'project.summary_pdf_exported')
            ->where('subject_id', $project->id)
            ->latest('id')
            ->first();
        $after = json_decode((string) $audit?->after, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(50, $after['progress']);
        $this->assertSame('attention', $after['health']);
        $this->assertSame($nextStage->id, $after['next_stage_id']);
    }
}
