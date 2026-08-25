<?php

namespace Tests\Feature;

use App\Models\Requirement;
use App\Models\RequirementCategory;
use App\Models\RequirementGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class RequirementTaxonomyFeatureTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_tree_preserves_uncategorized_requirements_and_dependency_cycles_are_rejected(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $status = $this->makeStatus('requirement', 'taxonomy-open', 'open');
        $first = Requirement::query()->create(['project_id' => $project->id, 'code' => 'REQ-1', 'title' => 'الدخول', 'type' => 'security', 'status_id' => $status->id]);
        $second = Requirement::query()->create(['project_id' => $project->id, 'code' => 'REQ-2', 'title' => 'الصلاحيات', 'type' => 'security', 'status_id' => $status->id]);

        $this->actingAs($manager)->getJson(route('projects.requirement-taxonomy.index', $project))
            ->assertOk()->assertJsonCount(2, 'data.uncategorized.requirements');
        $this->postJson(route('projects.requirement-relations.store', [$project, $first]), [
            'target_requirement_id' => $second->id, 'type' => 'depends_on',
        ])->assertCreated();
        $this->postJson(route('projects.requirement-relations.store', [$project, $second]), [
            'target_requirement_id' => $first->id, 'type' => 'depends_on',
        ])->assertUnprocessable()->assertJsonValidationErrors('target_requirement_id');
        $this->postJson(route('projects.requirement-relations.store', [$project, $first]), [
            'target_requirement_id' => $first->id, 'type' => 'related_to',
        ])->assertUnprocessable();
    }

    public function test_groups_can_be_moved_and_merged_without_losing_requirements(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $status = $this->makeStatus('requirement', 'taxonomy-merge-open', 'open');
        $firstCategory = RequirementCategory::query()->create(['project_id' => $project->id, 'name' => 'وظيفية', 'position' => 0]);
        $secondCategory = RequirementCategory::query()->create(['project_id' => $project->id, 'name' => 'تقنية', 'position' => 1]);
        $source = RequirementGroup::query()->create(['project_id' => $project->id, 'category_id' => $firstCategory->id, 'name' => 'قديمة']);
        $target = RequirementGroup::query()->create(['project_id' => $project->id, 'category_id' => $secondCategory->id, 'name' => 'موحدة']);
        $requirement = Requirement::query()->create([
            'project_id' => $project->id, 'group_id' => $source->id, 'code' => 'REQ-MERGE',
            'title' => 'متطلب محفوظ', 'type' => 'functional', 'status_id' => $status->id,
        ]);

        $this->actingAs($manager)->putJson(route('projects.requirement-groups.update', [$project, $source]), [
            'category_id' => $secondCategory->id,
        ])->assertOk()->assertJsonPath('data.category_id', $secondCategory->id);

        $this->postJson(route('projects.requirement-groups.merge', [$project, $source]), [
            'target_group_id' => $target->id,
        ])->assertOk();

        $this->assertDatabaseMissing('requirement_groups', ['id' => $source->id]);
        $this->assertSame($target->id, $requirement->fresh()->group_id);
    }
}
