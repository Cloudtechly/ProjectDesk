<?php

namespace Tests\Feature;

use App\Contracts\MalwareScanner;
use App\Models\AttachmentLink;
use App\Models\FileObject;
use App\Models\Requirement;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeMalwareScanner;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ProjectFileTargetLinkTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('project-desk.uploads.disk', 'local');
        $this->app->instance(MalwareScanner::class, FakeMalwareScanner::clean());
    }

    public function test_uploads_can_target_the_project_a_task_or_a_requirement(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $task = $this->task($project->id, $manager->id, 'TSK-SEARCH');
        $requirement = $this->requirement($project->id, $manager->id, 'REQ-SEARCH');

        $taskUpload = $this->actingAs($member)->post(
            route('projects.files.store', $project),
            [
                'file' => $this->pdf('task.pdf'),
                'target_type' => 'task',
                'target_id' => $task->id,
            ],
            ['Accept' => 'application/json'],
        )->assertCreated()
            ->assertJsonPath('data.target.type', 'task')
            ->assertJsonPath('data.target.id', $task->id)
            ->assertJsonPath('data.target.code', 'TSK-SEARCH')
            ->assertJsonMissingPath('data.storage_key');

        $requirementUpload = $this->actingAs($member)->post(
            route('projects.files.store', $project),
            [
                'file' => $this->pdf('requirement.pdf'),
                'target_type' => 'requirement',
                'target_id' => $requirement->id,
            ],
            ['Accept' => 'application/json'],
        )->assertCreated()
            ->assertJsonPath('data.target.type', 'requirement')
            ->assertJsonPath('data.target.id', $requirement->id);

        $projectUpload = $this->actingAs($member)->post(
            route('projects.files.store', $project),
            ['file' => $this->pdf('project.pdf')],
            ['Accept' => 'application/json'],
        )->assertCreated()->assertJsonPath('data.target.type', 'project');

        $this->assertDatabaseHas('attachment_links', [
            'id' => $taskUpload->json('data.link_id'),
            'project_id' => $project->id,
            'task_id' => $task->id,
            'requirement_id' => null,
        ]);
        $this->assertDatabaseHas('attachment_links', [
            'id' => $requirementUpload->json('data.link_id'),
            'project_id' => $project->id,
            'task_id' => null,
            'requirement_id' => $requirement->id,
        ]);

        $this->actingAs($member)->getJson(route('projects.files.index', $project))
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data');
        $this->actingAs($member)->get(route('files.download', $projectUpload->json('data.id')))
            ->assertOk();
    }

    public function test_cross_project_and_archived_targets_are_rejected_before_file_persistence(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $otherProject = $this->makeProject($manager, $project->status);
        $otherTask = $this->task($otherProject->id, $manager->id, 'TSK-OTHER');
        $archivedRequirement = $this->requirement($project->id, $manager->id, 'REQ-OLD');
        $archivedRequirement->update(['archived_at' => now()]);

        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            [
                'file' => $this->pdf('cross-project.pdf'),
                'target_type' => 'task',
                'target_id' => $otherTask->id,
            ],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('target_id');

        $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            [
                'file' => $this->pdf('archived-target.pdf'),
                'target_type' => 'requirement',
                'target_id' => $archivedRequirement->id,
            ],
            ['Accept' => 'application/json'],
        )->assertUnprocessable()->assertJsonValidationErrors('target_id');

        $this->assertDatabaseCount('file_objects', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_archiving_is_scoped_to_the_selected_link_and_keeps_other_links_downloadable(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $task = $this->task($project->id, $manager->id, 'TSK-LINK');
        $requirement = $this->requirement($project->id, $manager->id, 'REQ-LINK');
        $response = $this->actingAs($manager)->post(
            route('projects.files.store', $project),
            [
                'file' => $this->pdf('shared.pdf'),
                'target_type' => 'task',
                'target_id' => $task->id,
            ],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $file = FileObject::query()->findOrFail($response->json('data.id'));
        $taskLink = AttachmentLink::query()->findOrFail($response->json('data.link_id'));
        $requirementLink = AttachmentLink::query()->create([
            'file_object_id' => $file->id,
            'project_id' => $project->id,
            'requirement_id' => $requirement->id,
        ]);

        $this->actingAs($manager)->postJson(
            route('projects.files.links.archive', [$project, $file, $taskLink]),
        )->assertOk();

        $this->assertNotNull($taskLink->fresh()->archived_at);
        $this->assertNull($requirementLink->fresh()->archived_at);
        $this->actingAs($manager)->get(route('files.download', $file))->assertOk();
        $this->actingAs($manager)->getJson(route('projects.files.index', $project))
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.link_id', $requirementLink->id);
        $this->actingAs($manager)->getJson(route('projects.files.index', [
            'project' => $project,
            'include_archived' => 1,
        ]))->assertJsonPath('meta.total', 2);

        $this->actingAs($manager)->postJson(
            route('projects.files.links.restore', [$project, $file, $taskLink]),
        )->assertOk();
        $this->assertNull($taskLink->fresh()->archived_at);
    }

    public function test_target_search_is_authorized_scoped_and_excludes_archived_records(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $outsider = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $match = $this->task($project->id, $manager->id, 'TSK-FIND');
        $archived = $this->task($project->id, $manager->id, 'TSK-FIND-OLD');
        $archived->update(['archived_at' => now()]);

        $this->actingAs($member)->getJson(route('projects.files.targets', [
            'project' => $project,
            'type' => 'task',
            'q' => 'FIND',
        ]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
        $this->actingAs($outsider)->getJson(route('projects.files.targets', [
            'project' => $project,
            'type' => 'task',
        ]))->assertForbidden();
    }

    private function task(int $projectId, int $ownerId, string $code): Task
    {
        $status = $this->makeStatus('task', strtolower($code), 'pending');

        return Task::query()->create([
            'project_id' => $projectId,
            'code' => $code,
            'title' => 'Task '.$code,
            'status_id' => $status->id,
            'priority' => 'medium',
            'assignee_id' => $ownerId,
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);
    }

    private function requirement(int $projectId, int $ownerId, string $code): Requirement
    {
        $status = $this->makeStatus('requirement', strtolower($code), 'pending');

        return Requirement::query()->create([
            'project_id' => $projectId,
            'code' => $code,
            'title' => 'Requirement '.$code,
            'status_id' => $status->id,
            'priority' => 'medium',
            'owner_id' => $ownerId,
        ]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
        );
    }
}
