<?php

namespace Tests\Feature;

use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class SalesDocumentAuthorizationTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_admin_has_global_access_and_project_manager_has_creator_scoped_access(): void
    {
        $admin = $this->makeUser();
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $adminTemplate = $this->makeTemplate($admin, 'Admin template');
        $owned = $this->makeTemplate($manager, 'Owned template');
        $other = $this->makeTemplate($otherManager, 'Other template');

        $this->actingAs($manager)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/index')
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $owned->id)
                ->where('documentTypes', ['invoice'])
                ->where('statuses.invoice', ['draft', 'archived'])
                ->where('canCreate', true)
                ->where('canCreateProjectless', true));

        $this->actingAs($admin)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 3)
                ->where('canCreate', true));

        $this->actingAs($manager)->getJson(route('sales.show', $adminTemplate))->assertForbidden();
        $this->getJson(route('sales.show', $other))->assertForbidden();
        $this->getJson(route('sales.show', $owned))->assertOk();
    }

    public function test_members_and_viewers_cannot_enter_or_create_templates(): void
    {
        foreach (['member', 'viewer'] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user)->get(route('sales.index'))->assertForbidden();
            $this->postJson(route('sales.store'), $this->payload())->assertForbidden();
        }
    }

    public function test_project_manager_can_create_without_context_and_optional_context_does_not_grant_access(): void
    {
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $visibleProject = $this->makeProject($manager);
        $hiddenProject = $this->makeProject($otherManager, $visibleProject->status);

        $this->actingAs($manager)
            ->postJson(route('sales.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('document.projectId', null);

        $this->postJson(route('sales.store'), $this->payload([
            'client_id' => $visibleProject->client_id,
            'project_id' => $visibleProject->id,
        ]))->assertCreated();

        $this->postJson(route('sales.store'), $this->payload([
            'client_id' => $hiddenProject->client_id,
            'project_id' => $hiddenProject->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'project_id']);

        $ownedByOther = $this->makeTemplate($otherManager, 'Hidden by creator', $visibleProject->id);
        $this->getJson(route('sales.show', $ownedByOther))->assertForbidden();
    }

    public function test_only_owner_or_admin_can_mutate_and_archived_templates_require_restore(): void
    {
        $admin = $this->makeUser();
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $document = $this->makeTemplate($manager, 'Owned template');

        $this->actingAs($otherManager)
            ->putJson(route('sales.update', $document), $this->payload(['lock_version' => 1]))
            ->assertForbidden();
        $this->postJson(route('sales.archive', $document), ['lock_version' => 1])->assertForbidden();
        $this->postJson(route('sales.duplicate', $document), ['lock_version' => 1])->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('sales.archive', $document), ['lock_version' => 1])
            ->assertOk();
        $this->putJson(route('sales.update', $document), $this->payload(['lock_version' => 2]))
            ->assertForbidden();
        $this->postJson(route('sales.restore', $document), ['lock_version' => 2])->assertOk();
    }

    private function makeTemplate(User $creator, string $title, ?int $projectId = null): SalesDocument
    {
        return SalesDocument::query()->create([
            'type' => 'invoice',
            'number' => 'CT-INV-'.fake()->unique()->numerify('#####'),
            'title' => $title,
            'status' => 'draft',
            'project_id' => $projectId,
            'issue_date' => now()->toDateString(),
            'currency' => 'LYD',
            'discount_rate' => 0,
            'tax_rate' => 0,
            'lock_version' => 1,
            'created_by' => $creator->id,
        ]);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'type' => 'invoice',
            'title' => 'Template',
            'status' => 'draft',
            'client_id' => null,
            'project_id' => null,
            'issue_date' => null,
            'due_date' => null,
            'currency' => 'LYD',
            'discount_rate' => '0',
            'tax_rate' => '0',
            'line_items' => [[
                'name' => 'Service', 'quantity' => '1', 'unit' => 'unit', 'unit_price' => '100.00',
            ]],
        ], $overrides);
    }
}
