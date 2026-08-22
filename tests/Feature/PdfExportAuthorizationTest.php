<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Requirement;
use App\Models\SalesDocument;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class PdfExportAuthorizationTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_invoice_template_generates_a_private_audited_pdf_with_optional_context(): void
    {
        $admin = $this->makeUser();
        $document = $this->makeTemplate($admin);

        $response = $this->actingAs($admin)->get(route('sales.pdf', $document));
        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $contents = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertGreaterThan(1000, strlen($contents));
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'sales_document.pdf_exported',
            'subject_type' => SalesDocument::class,
            'subject_id' => $document->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_template_pdf_uses_creator_scope_and_legacy_documents_are_not_exportable(): void
    {
        $admin = $this->makeUser();
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $document = $this->makeTemplate($manager);

        $this->actingAs($manager)->get(route('sales.pdf', $document))->assertOk();
        $this->actingAs($otherManager)->get(route('sales.pdf', $document))->assertForbidden();
        $this->actingAs($member)->get(route('sales.pdf', $document))->assertForbidden();
        $this->actingAs($admin)->get(route('sales.pdf', $document))->assertOk();

        $legacy = SalesDocument::query()->create([
            'type' => 'receipt',
            'number' => 'LEGACY-RECEIPT-PDF',
            'title' => 'Legacy receipt',
            'status' => 'issued',
            'currency' => 'LYD',
            'created_by' => $admin->id,
        ]);
        $this->get(route('sales.pdf', $legacy))->assertNotFound();
        $this->assertDatabaseHas('sales_documents', ['id' => $legacy->id, 'type' => 'receipt']);
    }

    public function test_project_summary_pdf_contains_authorized_current_scope_and_is_audited(): void
    {
        $admin = $this->makeUser();
        $member = $this->makeUser('member');
        $outsider = $this->makeUser('viewer');
        $project = $this->makeProject($admin);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $taskStatus = $this->makeStatus('task', 'pdf-open', 'in_progress');
        $requirementStatus = $this->makeStatus('requirement', 'pdf-approved', 'done');
        Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-PDF',
            'title' => 'مهمة ضمن ملخص المشروع',
            'status_id' => $taskStatus->id,
            'priority' => 'high',
            'assignee_id' => $member->id,
            'assigned_at' => now(),
            'start_at' => now(),
            'due_at' => now()->addWeek(),
        ]);
        Requirement::query()->create([
            'project_id' => $project->id,
            'code' => 'REQ-PDF',
            'title' => 'متطلب ضمن الملخص',
            'status_id' => $requirementStatus->id,
            'priority' => 'high',
        ]);

        $response = $this->actingAs($member)->get(route('projects.summary.pdf', $project));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'project.summary_pdf_exported',
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'actor_id' => $member->id,
        ]);
        $this->actingAs($outsider)->get(route('projects.summary.pdf', $project))->assertForbidden();
    }

    private function makeTemplate(User $creator): SalesDocument
    {
        $document = SalesDocument::query()->create([
            'type' => 'invoice',
            'number' => 'CT-INV-'.fake()->unique()->numerify('####'),
            'title' => 'قالب فاتورة للاختبار',
            'status' => 'draft',
            'client_id' => null,
            'project_id' => null,
            'issue_date' => null,
            'due_date' => null,
            'currency' => 'LYD',
            'discount_rate' => '10',
            'tax_rate' => '15',
            'client_snapshot' => null,
            'company_snapshot' => ['name' => 'CloudTech', 'address' => 'طرابلس، ليبيا'],
            'lock_version' => 1,
            'created_by' => $creator->id,
        ]);
        $document->lineItems()->createMany([
            ['name' => 'تحليل', 'quantity' => '2', 'unit' => 'مرحلة', 'unit_price' => '1000.00', 'position' => 1],
            ['name' => 'تطوير', 'quantity' => '3', 'unit' => 'مرحلة', 'unit_price' => '500.00', 'position' => 2],
        ]);

        return $document->fresh();
    }
}
