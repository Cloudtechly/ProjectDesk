<?php

namespace Tests\Feature;

use App\Models\FileObject;
use App\Models\Requirement;
use App\Models\RequirementAnalysisRun;
use App\Models\RequirementBook;
use App\Models\RequirementBookVersion;
use App\Models\RequirementCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class RequirementCandidateApprovalFeatureTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_approving_an_unchanged_candidate_reuses_the_requirement_instead_of_duplicating_it(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $status = $this->makeStatus('requirement', 'candidate-open', 'open');
        $requirement = Requirement::query()->create([
            'project_id' => $project->id, 'code' => 'REQ-UNCHANGED', 'title' => 'تسجيل الدخول',
            'description' => 'يسمح النظام بتسجيل الدخول.', 'type' => 'functional', 'priority' => 'high',
            'status_id' => $status->id, 'lock_version' => 1,
        ]);
        $file = FileObject::query()->create([
            'disk' => 'local', 'storage_key' => 'tests/book.pdf', 'original_name' => 'book.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', 'book'), 'scan_status' => 'clean',
            'uploaded_by' => $manager->id, 'uploaded_at' => now(),
        ]);
        $book = RequirementBook::query()->create(['project_id' => $project->id, 'title' => 'الكراسة']);
        $version = RequirementBookVersion::query()->create([
            'requirement_book_id' => $book->id, 'version_number' => 1, 'status' => 'approved',
            'file_object_id' => $file->id, 'uploaded_by' => $manager->id, 'uploaded_at' => now(),
            'is_current' => true, 'lock_version' => 1,
        ]);
        $run = RequirementAnalysisRun::query()->create([
            'project_id' => $project->id, 'requirement_book_version_id' => $version->id,
            'requested_by' => $manager->id, 'status' => 'review_ready',
            'file_fingerprint' => $file->checksum_sha256, 'instruction_version' => 'test-v1',
            'model' => 'qwen3:test', 'context_size' => 8192,
        ]);
        $candidate = RequirementCandidate::query()->create([
            'analysis_run_id' => $run->id, 'candidate_key' => hash('sha256', 'candidate'),
            'category_name' => 'وظيفية', 'group_name' => 'الدخول', 'type' => 'functional',
            'title' => $requirement->title, 'description' => $requirement->description,
            'acceptance_criteria' => [], 'priority' => 'high', 'relations' => [], 'ambiguities' => [],
            'source_locator_type' => 'page', 'source_locator' => '1',
            'source_excerpt' => 'يسمح النظام بتسجيل الدخول.', 'confidence' => 0.99,
            'status' => 'pending', 'change_type' => 'unchanged',
            'matched_requirement_id' => $requirement->id, 'affected_entities' => [],
        ]);

        $this->actingAs($manager)->postJson(route('projects.requirement-candidates.decide', [$project, $run]), [
            'decisions' => [['id' => $candidate->id, 'action' => 'approve']],
        ])->assertOk()->assertJsonPath('data.approved_requirement_ids.0', $requirement->id);

        $this->assertDatabaseCount('requirements', 1);
        $this->assertDatabaseHas('requirement_candidates', [
            'id' => $candidate->id, 'status' => 'approved', 'approved_requirement_id' => $requirement->id,
        ]);
        $this->assertDatabaseHas('requirement_sources', [
            'requirement_id' => $requirement->id, 'requirement_book_version_id' => $version->id,
        ]);
    }
}
