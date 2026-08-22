<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkflowStatusController;
use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class WorkflowStatusSettingsTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->prefix('/_tests/workflow-statuses')->group(function (): void {
            Route::get('/{entityType}', [WorkflowStatusController::class, 'index']);
            Route::match(['put', 'patch'], '/{entityType}', [WorkflowStatusController::class, 'update']);
        });
    }

    public function test_active_admin_can_read_each_supported_workflow_with_read_only_fields_and_usage(): void
    {
        $admin = $this->makeUser('admin');
        $projectOpen = $this->makeStatus('project', 'planning', 'open');
        $taskOpen = $this->makeStatus('task', 'new', 'open');
        $requirementOpen = $this->makeStatus('requirement', 'draft', 'open');
        $project = $this->makeProject($admin, $projectOpen);

        foreach (['project', 'task', 'requirement'] as $entityType) {
            $this->actingAs($admin)
                ->getJson("/_tests/workflow-statuses/{$entityType}")
                ->assertOk()
                ->assertJsonPath('data.entity_type', $entityType)
                ->assertJsonCount(1, 'data.statuses')
                ->assertJsonStructure([
                    'data' => [
                        'entity_type',
                        'statuses' => [[
                            'id', 'entity_type', 'code', 'label', 'semantic',
                            'color', 'position', 'is_active', 'usage_count',
                        ]],
                    ],
                ]);
        }

        $this->getJson('/_tests/workflow-statuses/project')
            ->assertJsonPath('data.statuses.0.usage_count', 1)
            ->assertJsonPath('data.statuses.0.semantic', 'open')
            ->assertJsonPath('data.statuses.0.code', 'planning');
        $this->assertSame($projectOpen->id, $project->status_id);
        $this->assertSame(0, $taskOpen->tasks()->count());
        $this->assertSame(0, $requirementOpen->requirements()->count());
    }

    public function test_only_active_non_archived_admin_can_read_or_update_workflows(): void
    {
        $open = $this->makeStatus('task', 'new', 'open');
        $payload = ['statuses' => [$this->statusInput($open)]];
        $users = [
            $this->makeUser('project_manager'),
            $this->makeUser('member'),
            $this->makeUser('admin', 'inactive'),
            User::factory()->create([
                'global_role' => 'admin',
                'status' => 'active',
                'archived_at' => now(),
            ]),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->getJson('/_tests/workflow-statuses/task')->assertForbidden();
            $this->actingAs($user)->putJson('/_tests/workflow-statuses/task', $payload)->assertForbidden();
        }

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_admin_can_reorder_and_edit_mutable_fields_atomically(): void
    {
        $admin = $this->makeUser('admin');
        $open = $this->makeStatus('task', 'new', 'open');
        $progress = $this->makeStatus('task', 'in_progress', 'in_progress');
        $done = $this->makeStatus('task', 'completed', 'done');

        $response = $this->actingAs($admin)->putJson('/_tests/workflow-statuses/task', [
            'statuses' => [
                $this->statusInput($done, label: 'Finished', color: '#00aa00', position: 10),
                $this->statusInput($open, label: 'Ready', color: '#112233', position: 20),
                $this->statusInput($progress, label: 'Doing', color: '#445566', position: 30),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.statuses.0.id', $done->id)
            ->assertJsonPath('data.statuses.0.color', '#00AA00')
            ->assertJsonPath('data.statuses.1.id', $open->id)
            ->assertJsonPath('data.statuses.2.id', $progress->id);

        $this->assertDatabaseHas('workflow_statuses', [
            'id' => $open->id,
            'code' => 'new',
            'semantic' => 'open',
            'label' => 'Ready',
            'color' => '#112233',
            'position' => 20,
            'is_active' => true,
        ]);
        $this->assertSame(3, DB::table('activity_logs')->where('action', 'workflow_status.updated')->count());

        $this->patchJson('/_tests/workflow-statuses/task', [
            'statuses' => [
                $this->statusInput($done->fresh(), label: 'Finished', color: '#00AA00', position: 10),
                $this->statusInput($open->fresh(), label: 'Ready', color: '#112233', position: 20),
                $this->statusInput($progress->fresh(), label: 'Doing', color: '#445566', position: 30),
            ],
        ])->assertOk();

        $this->assertSame(3, DB::table('activity_logs')->where('action', 'workflow_status.updated')->count());
    }

    public function test_validation_rejects_invalid_color_duplicate_position_foreign_status_and_immutable_code(): void
    {
        $admin = $this->makeUser('admin');
        $open = $this->makeStatus('task', 'new', 'open');
        $progress = $this->makeStatus('task', 'in_progress', 'in_progress');
        $projectStatus = $this->makeStatus('project', 'planning', 'open');
        $this->actingAs($admin);

        $this->putJson('/_tests/workflow-statuses/task', [
            'statuses' => [
                $this->statusInput($open, color: '406386', position: 10),
                $this->statusInput($progress, position: 10),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'statuses.0.color',
            'statuses.1.position',
        ]);

        $this->putJson('/_tests/workflow-statuses/task', [
            'statuses' => [
                $this->statusInput($open),
                $this->statusInput($projectStatus, position: 20),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('statuses.1.id');

        $immutableInjection = $this->statusInput($open);
        $immutableInjection['code'] = 'forged-code';
        $this->putJson('/_tests/workflow-statuses/task', [
            'statuses' => [$immutableInjection, $this->statusInput($progress, position: 20)],
        ])->assertUnprocessable()->assertJsonValidationErrors('statuses.0');

        $this->putJson('/_tests/workflow-statuses/unsupported', ['statuses' => []])->assertNotFound();
        $this->getJson('/_tests/workflow-statuses/unsupported')->assertNotFound();
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_admin_can_change_a_semantic_when_an_initial_status_remains_active(): void
    {
        $admin = $this->makeUser('admin');
        $open = $this->makeStatus('task', 'new', 'open');
        $progress = $this->makeStatus('task', 'in_progress', 'in_progress');

        $this->actingAs($admin)->putJson('/_tests/workflow-statuses/task', [
            'statuses' => [
                $this->statusInput($open),
                $this->statusInput($progress, position: 20, semantic: 'done'),
            ],
        ])->assertOk()
            ->assertJsonPath('data.statuses.1.semantic', 'done');

        $this->assertDatabaseHas('workflow_statuses', [
            'id' => $progress->id,
            'semantic' => 'done',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => WorkflowStatus::class,
            'subject_id' => $progress->id,
            'action' => 'workflow_status.updated',
        ]);
    }

    public function test_full_collection_contract_prevents_omission_and_never_deletes_statuses(): void
    {
        $admin = $this->makeUser('admin');
        $open = $this->makeStatus('requirement', 'draft', 'open');
        $done = $this->makeStatus('requirement', 'delivered', 'done');

        $this->actingAs($admin)->putJson('/_tests/workflow-statuses/requirement', [
            'statuses' => [$this->statusInput($open)],
        ])->assertUnprocessable()->assertJsonValidationErrors('statuses');

        $this->assertDatabaseHas('workflow_statuses', ['id' => $done->id]);
        $this->assertDatabaseCount('workflow_statuses', 2);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_last_active_initial_status_cannot_be_disabled(): void
    {
        $admin = $this->makeUser('admin');
        $open = $this->makeStatus('task', 'new', 'open');
        $progress = $this->makeStatus('task', 'in_progress', 'in_progress');

        $this->actingAs($admin)->putJson('/_tests/workflow-statuses/task', [
            'statuses' => [
                $this->statusInput($open, active: false),
                $this->statusInput($progress, position: 20),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('statuses');

        $this->assertTrue($open->fresh()->is_active);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_referenced_status_cannot_be_disabled_and_all_other_changes_are_rolled_back(): void
    {
        $admin = $this->makeUser('admin');
        $open = $this->makeStatus('project', 'planning', 'open');
        $used = $this->makeStatus('project', 'active', 'in_progress');
        $project = $this->makeProject($admin, $used);

        $this->actingAs($admin)->putJson('/_tests/workflow-statuses/project', [
            'statuses' => [
                $this->statusInput($open, label: 'Changed but rolled back'),
                $this->statusInput($used, position: 20, active: false),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('statuses.1.is_active');

        $this->assertSame($used->id, $project->fresh()->status_id);
        $this->assertSame('planning', $open->fresh()->label);
        $this->assertTrue($used->fresh()->is_active);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_used_status_can_still_be_renamed_recolored_and_reordered_while_active(): void
    {
        $admin = $this->makeUser('admin');
        $open = $this->makeStatus('project', 'planning', 'open');
        $used = $this->makeStatus('project', 'active', 'in_progress');
        $this->makeProject($admin, $used);

        $this->actingAs($admin)->patchJson('/_tests/workflow-statuses/project', [
            'statuses' => [
                $this->statusInput($used, label: 'Execution', color: '#abcdef', position: 10),
                $this->statusInput($open, position: 20),
            ],
        ])->assertOk()
            ->assertJsonPath('data.statuses.0.id', $used->id)
            ->assertJsonPath('data.statuses.0.usage_count', 1);

        $this->assertDatabaseHas('workflow_statuses', [
            'id' => $used->id,
            'label' => 'Execution',
            'color' => '#ABCDEF',
            'position' => 10,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{id: int, label: string, semantic: string, color: string, position: int, is_active: bool}
     */
    private function statusInput(
        WorkflowStatus $status,
        ?string $label = null,
        ?string $color = null,
        ?int $position = null,
        ?bool $active = null,
        ?string $semantic = null,
    ): array {
        return [
            'id' => $status->id,
            'label' => $label ?? $status->label,
            'semantic' => $semantic ?? $status->semantic,
            'color' => $color ?? $status->color,
            'position' => $position ?? $status->position,
            'is_active' => $active ?? $status->is_active,
        ];
    }
}
