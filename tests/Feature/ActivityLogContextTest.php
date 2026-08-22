<?php

namespace Tests\Feature;

use App\Models\FileObject;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ActivityLogContextTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_request_context_generates_and_returns_trace_identifiers(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertHeader('X-Correlation-Id');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/',
            (string) $response->headers->get('X-Request-Id'),
        );
    }

    public function test_activity_logger_records_project_and_valid_correlation_context(): void
    {
        $actor = $this->makeUser('admin');
        $project = $this->makeProject($actor);
        $request = Request::create('/projects/'.$project->id, 'POST', server: [
            'HTTP_X_REQUEST_ID' => 'req-123',
            'HTTP_X_CORRELATION_ID' => 'flow-456',
        ]);

        app(ActivityLogger::class)->record($project, 'project.tested', $actor, request: $request);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'request_id' => 'req-123',
            'correlation_id' => 'flow-456',
        ]);
    }

    public function test_file_activity_inherits_the_linked_project_context(): void
    {
        $actor = $this->makeUser('admin');
        $project = $this->makeProject($actor);
        $file = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'tests/audit-context.pdf',
            'original_name' => 'context.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 5,
            'checksum_sha256' => str_repeat('a', 64),
            'scan_status' => 'structurally_safe',
            'uploaded_by' => $actor->id,
            'uploaded_at' => now(),
        ]);
        DB::table('attachment_links')->insert([
            'file_object_id' => $file->id,
            'project_id' => $project->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ActivityLogger::class)->record($file, 'project_file.tested', $actor);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'subject_type' => FileObject::class,
            'subject_id' => $file->id,
        ]);
        $this->assertNotNull(DB::table('activity_logs')->value('correlation_id'));
    }

    public function test_project_activity_is_isolated_and_paginated_without_truncating_history(): void
    {
        $actor = $this->makeUser('admin');
        $status = $this->makeStatus('project', 'activity-active', 'in_progress');
        $project = $this->makeProject($actor, $status);
        $otherProject = $this->makeProject($actor, $status);
        $logger = app(ActivityLogger::class);

        foreach (range(1, 30) as $sequence) {
            $logger->record($project, "project.volume.{$sequence}", $actor);
        }
        $logger->record($otherProject, 'project.hidden', $actor);

        $this->actingAs($actor)
            ->get(route('projects.show', [
                'project' => $project,
                'tab' => 'activity',
                'activity_page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->where('activity.current_page', 2)
                ->where('activity.total', 30)
                ->has('activity.data', 5)
                ->where('activity.data.0.action', 'project.volume.5')
                ->missing('activity.data.5'));
    }
}
