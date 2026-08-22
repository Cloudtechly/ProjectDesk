<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SalesDocument;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class SalesDocumentWorkflowTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_admin_creates_a_standalone_invoice_template_with_optional_context_and_server_number(): void
    {
        $admin = $this->makeUser();

        $response = $this->actingAs($admin)
            ->postJson(route('sales.store'), $this->templatePayload([
                'client_id' => null,
                'project_id' => null,
                'issue_date' => null,
                'due_date' => null,
            ]))
            ->assertCreated()
            ->assertJsonPath('document.type', 'invoice')
            ->assertJsonPath('document.status', 'draft')
            ->assertJsonPath('document.clientId', null)
            ->assertJsonPath('document.projectId', null)
            ->assertJsonPath('document.issueDate', null)
            ->assertJsonPath('document.dueDate', null)
            ->assertJsonPath('document.totals.total', '210.00');

        $number = (string) $response->json('document.number');
        $this->assertMatchesRegularExpression('/^CT-INV-\d{4}-001$/', $number);
        $this->assertDatabaseHas('sales_documents', [
            'number' => $number,
            'type' => 'invoice',
            'status' => 'draft',
            'client_id' => null,
            'project_id' => null,
            'issue_date' => null,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'sales_document.created']);
    }

    public function test_legacy_types_accounting_states_and_details_are_rejected(): void
    {
        $admin = $this->makeUser();
        $this->actingAs($admin);

        foreach (['proposal', 'receipt', 'letterhead'] as $legacyType) {
            $this->postJson(route('sales.store'), $this->templatePayload(['type' => $legacyType]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('type');
        }

        foreach (['sent', 'paid', 'cancelled', 'issued', 'accepted'] as $accountingStatus) {
            $this->postJson(route('sales.store'), $this->templatePayload(['status' => $accountingStatus]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        }

        $this->postJson(route('sales.store'), $this->templatePayload([
            'source_document_id' => 1,
            'proposal' => ['includes_contract' => true],
            'receipt' => ['amount' => 100],
            'letter' => ['body' => 'legacy'],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'source_document_id', 'proposal', 'receipt', 'letter',
        ]);

        $this->assertDatabaseCount('sales_documents', 0);
    }

    public function test_update_replaces_lines_snapshots_optional_client_and_rejects_stale_version(): void
    {
        $admin = $this->makeUser();
        $client = $this->makeClient();
        $this->actingAs($admin);

        $created = $this->postJson(route('sales.store'), $this->templatePayload([
            'client_id' => $client->id,
        ]))->assertCreated();
        $id = (int) $created->json('document.id');
        $number = (string) $created->json('document.number');
        $this->assertSame($client->name, $created->json('document.clientSnapshot.name'));

        $update = $this->templatePayload([
            'title' => 'قالب محدث',
            'client_id' => null,
            'lock_version' => 1,
            'line_items' => [[
                'name' => 'استشارة',
                'quantity' => '3',
                'unit' => 'ساعة',
                'unit_price' => '50.00',
            ]],
        ]);
        $this->putJson(route('sales.update', $id), $update)
            ->assertOk()
            ->assertJsonPath('document.number', $number)
            ->assertJsonPath('document.clientId', null)
            ->assertJsonPath('document.clientSnapshot', null)
            ->assertJsonPath('document.lockVersion', 2)
            ->assertJsonPath('document.totals.total', '157.50');

        $this->putJson(route('sales.update', $id), $update)->assertConflict();
        $this->assertDatabaseCount('sales_line_items', 1);
        $this->assertDatabaseHas('sales_documents', [
            'id' => $id,
            'number' => $number,
            'title' => 'قالب محدث',
            'lock_version' => 2,
        ]);
    }

    public function test_archive_restore_and_duplicate_are_locked_audited_and_non_destructive(): void
    {
        $admin = $this->makeUser();
        $this->actingAs($admin);
        $created = $this->postJson(route('sales.store'), $this->templatePayload())->assertCreated();
        $id = (int) $created->json('document.id');
        $number = (string) $created->json('document.number');

        $this->postJson(route('sales.archive', $id), ['lock_version' => 1])
            ->assertOk()
            ->assertJsonPath('document.status', 'archived')
            ->assertJsonPath('document.lockVersion', 2);
        $this->postJson(route('sales.archive', $id), ['lock_version' => 1])->assertForbidden();

        $copy = $this->postJson(route('sales.duplicate', $id), ['lock_version' => 2])
            ->assertCreated()
            ->assertJsonPath('document.status', 'draft')
            ->assertJsonPath('document.lockVersion', 1);
        $this->assertNotSame($number, $copy->json('document.number'));

        $this->postJson(route('sales.restore', $id), ['lock_version' => 1])->assertConflict();
        $this->postJson(route('sales.restore', $id), ['lock_version' => 2])
            ->assertOk()
            ->assertJsonPath('document.status', 'draft')
            ->assertJsonPath('document.lockVersion', 3);
        $this->postJson(route('sales.restore', $id), ['lock_version' => 3])->assertForbidden();

        $this->assertDatabaseCount('sales_documents', 2);
        foreach (['sales_document.archived', 'sales_document.restored', 'sales_document.duplicated'] as $action) {
            $this->assertDatabaseHas('activity_logs', ['action' => $action]);
        }
    }

    public function test_legacy_records_remain_in_database_but_are_not_route_bindable_or_listed(): void
    {
        $admin = $this->makeUser();
        $legacy = SalesDocument::query()->create([
            'type' => 'proposal',
            'number' => 'LEGACY-PROP-001',
            'title' => 'عرض تاريخي محفوظ',
            'status' => 'accepted',
            'issue_date' => now()->toDateString(),
            'currency' => 'LYD',
            'created_by' => $admin->id,
        ]);
        $accountingInvoice = SalesDocument::query()->create([
            'type' => 'invoice',
            'number' => 'LEGACY-PAID-INVOICE-001',
            'title' => 'فاتورة محاسبية تاريخية محفوظة',
            'status' => 'paid',
            'issue_date' => now()->toDateString(),
            'currency' => 'LYD',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 0));
        $this->getJson(route('sales.show', $legacy))->assertNotFound();
        $this->get(route('sales.pdf', $legacy))->assertNotFound();
        $this->postJson(route('sales.archive', $legacy), ['lock_version' => 1])->assertNotFound();
        $this->getJson(route('sales.show', $accountingInvoice))->assertNotFound();
        $this->assertDatabaseHas('sales_documents', ['id' => $legacy->id, 'type' => 'proposal']);
        $this->assertDatabaseHas('sales_documents', [
            'id' => $accountingInvoice->id,
            'type' => 'invoice',
            'status' => 'paid',
        ]);
    }

    public function test_invoice_prefix_and_padding_are_configurable_without_exposing_legacy_prefixes(): void
    {
        $admin = $this->makeUser();
        SystemSetting::query()->create([
            'group' => 'company', 'key' => 'invoice_prefix', 'value' => 'CLD-TPL', 'is_secret' => false,
        ]);
        SystemSetting::query()->create([
            'group' => 'company', 'key' => 'number_padding', 'value' => 5, 'is_secret' => false,
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('sales.store'), $this->templatePayload())
            ->assertCreated();

        $this->assertMatchesRegularExpression('/^CLD-TPL-\d{4}-00001$/', (string) $response->json('document.number'));
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function templatePayload(array $overrides = []): array
    {
        return array_replace([
            'type' => 'invoice',
            'title' => 'قالب فاتورة خدمات',
            'status' => 'draft',
            'client_id' => null,
            'project_id' => null,
            'issue_date' => '2026-08-12',
            'due_date' => '2026-08-26',
            'reference' => null,
            'currency' => 'LYD',
            'discount_rate' => '0',
            'tax_rate' => '5',
            'notes' => null,
            'line_items' => [[
                'name' => 'خدمة تقنية',
                'description' => null,
                'quantity' => '2',
                'unit' => 'خدمة',
                'unit_price' => '100.00',
            ]],
        ], $overrides);
    }

    private function makeClient(): Client
    {
        return Client::query()->create([
            'code' => 'CL-'.fake()->unique()->numerify('#####'),
            'name' => fake()->company(),
            'status' => 'active',
        ]);
    }
}
