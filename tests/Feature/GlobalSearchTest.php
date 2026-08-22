<?php

namespace Tests\Feature;

use App\Models\AttachmentLink;
use App\Models\Client;
use App\Models\FileObject;
use App\Models\Requirement;
use App\Models\SalesDocument;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use ProjectDeskTestData;
    use RefreshDatabase;

    public function test_search_requires_authentication_and_rejects_oversized_queries(): void
    {
        $this->getJson(route('search', ['q' => 'مشروع']))->assertUnauthorized();

        $this->actingAs($this->makeUser())
            ->getJson(route('search', ['q' => str_repeat('x', 81)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_member_only_receives_results_from_visible_projects_without_sensitive_fields(): void
    {
        $visibleManager = $this->makeUser('project_manager');
        $hiddenManager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $visibleColleague = User::factory()->create([
            'name' => 'أطلس زميل ظاهر',
            'job_title' => 'مطور',
            'phone' => '0910000000',
        ]);
        $hiddenColleague = User::factory()->create([
            'name' => 'أطلس زميل سري',
            'job_title' => 'محاسب',
            'phone' => '0920000000',
        ]);

        $projectStatus = $this->makeStatus('project', 'active', 'in_progress');
        $visibleProject = $this->makeProject($visibleManager, $projectStatus);
        $visibleProject->update(['name' => 'أطلس المشروع الظاهر']);
        $visibleProject->client->update(['name' => 'أطلس العميل الظاهر']);
        $visibleProject->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $visibleProject->members()->attach($visibleColleague, ['project_role' => 'member', 'status' => 'active']);

        $hiddenProject = $this->makeProject($hiddenManager, $projectStatus);
        $hiddenProject->update(['name' => 'أطلس المشروع السري']);
        $hiddenProject->client->update(['name' => 'أطلس العميل السري']);
        $hiddenProject->members()->attach($hiddenColleague, ['project_role' => 'member', 'status' => 'active']);

        $taskStatus = $this->makeStatus('task', 'open', 'open');
        Task::query()->create([
            'project_id' => $visibleProject->id,
            'code' => 'TSK-VISIBLE',
            'title' => 'أطلس المهمة الظاهرة',
            'status_id' => $taskStatus->id,
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        Task::query()->create([
            'project_id' => $hiddenProject->id,
            'code' => 'TSK-HIDDEN',
            'title' => 'أطلس المهمة السرية',
            'status_id' => $taskStatus->id,
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($member)->getJson(route('search', ['q' => 'أطلس']))
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'type', 'type_label', 'title', 'subtitle', 'href']],
                'meta' => ['query', 'total'],
            ]);

        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('المشروع الظاهر', $payload);
        $this->assertStringContainsString('المهمة الظاهرة', $payload);
        $this->assertStringContainsString('العميل الظاهر', $payload);
        $this->assertStringContainsString('زميل ظاهر', $payload);
        $this->assertStringNotContainsString('السري', $payload);
        $this->assertStringNotContainsString('0910000000', $payload);
        $this->assertStringNotContainsString($visibleColleague->email, $payload);
    }

    public function test_empty_and_wildcard_queries_do_not_enumerate_records(): void
    {
        $manager = $this->makeUser('project_manager');
        $this->makeProject($manager)->update(['name' => 'مشروع معروف']);

        $this->actingAs($manager)->getJson(route('search', ['q' => 'م']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($manager)->getJson(route('search', ['q' => '%%']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_shared_task_creation_capability_reflects_project_authority(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);

        $this->actingAs($manager)->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCreateTask', true));

        $this->actingAs($member)->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCreateTask', false));
    }

    public function test_project_manager_search_does_not_reveal_another_managers_unrelated_client(): void
    {
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        Client::query()->create([
            'created_by' => $otherManager->id,
            'code' => 'CL-HIDDEN-SEARCH',
            'name' => 'عميل سري للبحث',
            'status' => 'active',
        ]);

        $this->actingAs($manager)
            ->getJson(route('search', ['q' => 'عميل سري']))
            ->assertOk()
            ->assertJsonMissing(['title' => 'عميل سري للبحث']);
    }

    public function test_search_includes_authorized_requirements_sales_documents_and_files(): void
    {
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $projectStatus = $this->makeStatus('project', 'active', 'in_progress');
        $requirementStatus = $this->makeStatus('requirement', 'draft', 'open');
        $project = $this->makeProject($manager, $projectStatus);
        $hiddenProject = $this->makeProject($otherManager, $projectStatus);

        Requirement::query()->create([
            'project_id' => $project->id,
            'code' => 'REQ-NOOR',
            'title' => 'متطلب نورس المعتمد',
            'priority' => 'high',
            'status_id' => $requirementStatus->id,
        ]);
        Requirement::query()->create([
            'project_id' => $hiddenProject->id,
            'code' => 'REQ-NOOR-HIDDEN',
            'title' => 'متطلب نورس السري',
            'priority' => 'high',
            'status_id' => $requirementStatus->id,
        ]);

        SalesDocument::query()->create([
            'type' => 'invoice',
            'number' => 'CT-INV-NOOR-001',
            'title' => 'قالب فاتورة نورس',
            'status' => 'draft',
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'LYD',
            'created_by' => $manager->id,
        ]);
        SalesDocument::query()->create([
            'type' => 'invoice',
            'number' => 'CT-INV-NOOR-HIDDEN',
            'title' => 'قالب فاتورة نورس السري',
            'status' => 'draft',
            'client_id' => $hiddenProject->client_id,
            'project_id' => $hiddenProject->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'LYD',
            'created_by' => $otherManager->id,
        ]);
        SalesDocument::query()->create([
            'type' => 'proposal',
            'number' => 'LEGACY-PROP-NOOR',
            'title' => 'عرض نورس التاريخي المحفوظ',
            'status' => 'accepted',
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'LYD',
            'created_by' => $manager->id,
        ]);

        $visibleFile = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'search/noor-visible.pdf',
            'original_name' => 'وثيقة نورس.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('a', 64),
            'scan_status' => 'safe',
            'uploaded_by' => $manager->id,
            'uploaded_at' => now(),
        ]);
        AttachmentLink::query()->create([
            'file_object_id' => $visibleFile->id,
            'project_id' => $project->id,
        ]);
        $pendingScanFile = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'search/noor-awaiting-malware-scan.pdf',
            'original_name' => 'وثيقة نورس بانتظار المسح.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('c', 64),
            'scan_status' => 'structurally_safe',
            'uploaded_by' => $manager->id,
            'uploaded_at' => now(),
        ]);
        AttachmentLink::query()->create([
            'file_object_id' => $pendingScanFile->id,
            'project_id' => $project->id,
        ]);
        $hiddenFile = FileObject::query()->create([
            'disk' => 'local',
            'storage_key' => 'search/noor-hidden.pdf',
            'original_name' => 'وثيقة نورس السرية.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'checksum_sha256' => str_repeat('b', 64),
            'scan_status' => 'structurally_safe',
            'uploaded_by' => $otherManager->id,
            'uploaded_at' => now(),
        ]);
        AttachmentLink::query()->create([
            'file_object_id' => $hiddenFile->id,
            'project_id' => $hiddenProject->id,
        ]);

        $response = $this->actingAs($manager)
            ->getJson(route('search', ['q' => 'نورس']))
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('متطلب نورس المعتمد', $payload);
        $this->assertStringContainsString('قالب فاتورة نورس', $payload);
        $this->assertStringContainsString('وثيقة نورس.pdf', $payload);
        $this->assertStringNotContainsString('بانتظار المسح', $payload);
        $this->assertStringNotContainsString('عرض نورس التاريخي', $payload);
        $this->assertStringNotContainsString('السري', $payload);
    }
}
