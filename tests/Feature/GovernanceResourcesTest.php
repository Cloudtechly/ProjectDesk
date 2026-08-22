<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Meeting;
use App\Models\Requirement;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TimelineEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\Support\RegistersGovernanceRoutes;
use Tests\TestCase;

class GovernanceResourcesTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase, RegistersGovernanceRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerGovernanceRoutes();
    }

    public function test_requirement_crud_archive_and_optimistic_locking_are_audited(): void
    {
        $manager = $this->makeUser('project_manager');
        $otherManager = $this->makeUser('project_manager');
        $owner = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $otherProject = $this->makeProject($otherManager, $project->status);
        $project->members()->attach($owner, ['project_role' => 'member', 'status' => 'active']);
        $status = $this->makeStatus('requirement', 'governance-draft', 'open');

        $create = $this->actingAs($manager)->postJson(route('projects.requirements.store', $project), [
            'title' => 'تسجيل دخول العميل',
            'description' => 'وصف المتطلب',
            'acceptance_criteria' => 'يستطيع العميل الدخول.',
            'priority' => 'high',
            'status_id' => $status->id,
            'owner_id' => $owner->id,
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.code', 'REQ-00001')
            ->assertJsonPath('data.lock_version', 1);
        $requirement = Requirement::query()->firstOrFail();
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'requirement.created',
            'subject_id' => $requirement->id,
        ]);

        $this->actingAs($manager)->putJson(route('projects.requirements.update', [$project, $requirement]), [
            'code' => $requirement->code,
            'title' => 'تسجيل دخول العميل المحدّث',
            'description' => null,
            'acceptance_criteria' => 'دخول وخروج واستعادة كلمة المرور.',
            'priority' => 'critical',
            'status_id' => $status->id,
            'owner_id' => $owner->id,
            'lock_version' => 1,
        ])->assertOk()->assertJsonPath('data.lock_version', 2);

        $this->actingAs($manager)->putJson(route('projects.requirements.update', [$project, $requirement]), [
            'code' => $requirement->code,
            'title' => 'كتابة متعارضة',
            'priority' => 'medium',
            'status_id' => $status->id,
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');

        $this->actingAs($manager)->postJson(route('projects.requirements.archive', [$project, $requirement]), [
            'lock_version' => 2,
        ])->assertOk()->assertJsonPath('data.lock_version', 3);

        $requirement->refresh();
        $this->assertNotNull($requirement->archived_at);
        $this->actingAs($manager)->getJson(route('projects.requirements.index', $project))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($manager)->getJson(route('projects.requirements.index', [$project, 'include_archived' => 1]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('activity_logs', ['action' => 'requirement.archived', 'subject_id' => $requirement->id]);

        $this->actingAs($otherManager)->postJson(route('projects.requirements.restore', [$otherProject, $requirement]), [
            'lock_version' => 3,
        ])->assertNotFound();
        $this->actingAs($manager)->postJson(route('projects.requirements.restore', [$project, $requirement]), [
            'lock_version' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->actingAs($manager)->postJson(route('projects.requirements.restore', [$project, $requirement]), [
            'lock_version' => 3,
        ])->assertOk()
            ->assertJsonPath('data.archived_at', null)
            ->assertJsonPath('data.lock_version', 4);

        $this->assertNull($requirement->fresh()->archived_at);
        $this->actingAs($manager)->getJson(route('projects.requirements.index', $project))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('activity_logs', ['action' => 'requirement.restored', 'subject_id' => $requirement->id]);

        $this->actingAs($manager)->postJson(route('projects.requirements.archive', [$project, $requirement]), [
            'lock_version' => 4,
        ])->assertOk()->assertJsonPath('data.lock_version', 5);
        $project->update(['archived_at' => now()]);
        $this->actingAs($manager)->postJson(route('projects.requirements.restore', [$project, $requirement]), [
            'lock_version' => 5,
        ])->assertForbidden();
        $this->assertNotNull($requirement->fresh()->archived_at);
    }

    public function test_requirement_owner_and_project_scope_must_be_active(): void
    {
        $manager = $this->makeUser('project_manager');
        $outsider = $this->makeUser('member');
        $inactiveOwner = $this->makeUser('member', 'inactive');
        $project = $this->makeProject($manager);
        $status = $this->makeStatus('requirement', 'scope-draft', 'open');
        $payload = [
            'title' => 'متطلب محمي',
            'priority' => 'medium',
            'status_id' => $status->id,
            'owner_id' => $inactiveOwner->id,
        ];

        $this->actingAs($manager)->postJson(route('projects.requirements.store', $project), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $this->actingAs($outsider)->postJson(route('projects.requirements.store', $project), [
            ...$payload,
            'owner_id' => null,
        ])->assertForbidden();

        $project->update(['archived_at' => now()]);
        $this->actingAs($manager)->postJson(route('projects.requirements.store', $project), [
            ...$payload,
            'owner_id' => null,
        ])->assertForbidden();
        $this->assertDatabaseCount('requirements', 0);
    }

    public function test_archived_requirement_cannot_be_newly_linked_to_a_task(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $requirementStatus = $this->makeStatus('requirement', 'archived-link', 'open');
        $taskStatus = $this->makeStatus('task', 'governance-open', 'open');
        $requirement = Requirement::query()->create([
            'project_id' => $project->id,
            'code' => 'REQ-ARCHIVED',
            'title' => 'متطلب مؤرشف',
            'priority' => 'medium',
            'status_id' => $requirementStatus->id,
            'archived_at' => now(),
        ]);

        $this->actingAs($manager)->postJson(route('tasks.store'), [
            'project_id' => $project->id,
            'title' => 'مهمة مرتبطة خطأ',
            'status_id' => $taskStatus->id,
            'priority' => 'medium',
            'start_at' => '2026-08-12 09:00:00',
            'due_at' => '2026-08-13 09:00:00',
            'requirement_ids' => [$requirement->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('requirement_ids.0');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_risk_crud_preserves_score_fields_scope_and_audit_log(): void
    {
        $manager = $this->makeUser('project_manager');
        $owner = $this->makeUser('member');
        $otherManager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $otherProject = $this->makeProject($otherManager, $project->status);
        $project->members()->attach($owner, ['project_role' => 'member', 'status' => 'active']);

        $response = $this->actingAs($manager)->postJson(route('projects.risks.store', $project), [
            'title' => 'تأخر الاعتماد',
            'description' => 'قد يؤثر في التسليم.',
            'probability' => 4,
            'impact' => 5,
            'status' => 'open',
            'owner_id' => $owner->id,
            'mitigation' => 'جلسة اعتماد مركزة.',
            'due_at' => '2026-08-15 10:00:00',
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.probability', 4)
            ->assertJsonPath('data.impact', 5)
            ->assertJsonPath('data.lock_version', 1);
        $risk = Risk::query()->firstOrFail();
        $this->assertSame('2026-08-15 08:00:00', $risk->due_at?->format('Y-m-d H:i:s'));

        $this->actingAs($otherManager)->putJson(route('projects.risks.update', [$otherProject, $risk]), [
            'title' => 'نقل غير مسموح',
            'probability' => 1,
            'impact' => 1,
            'status' => 'closed',
            'lock_version' => 1,
        ])->assertNotFound();

        $this->actingAs($manager)->putJson(route('projects.risks.update', [$project, $risk]), [
            'title' => 'تأخر الاعتماد',
            'description' => null,
            'probability' => 2,
            'impact' => 3,
            'status' => 'mitigated',
            'owner_id' => $owner->id,
            'mitigation' => 'تم الاعتماد.',
            'due_at' => null,
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', 'mitigated')
            ->assertJsonPath('data.lock_version', 2);

        $this->actingAs($manager)->putJson(route('projects.risks.update', [$project, $risk]), [
            'title' => 'كتابة قديمة لا تحفظ',
            'description' => null,
            'probability' => 1,
            'impact' => 1,
            'status' => 'accepted',
            'owner_id' => $owner->id,
            'mitigation' => null,
            'due_at' => null,
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertDatabaseHas('risks', [
            'id' => $risk->id,
            'title' => 'تأخر الاعتماد',
            'status' => 'mitigated',
            'lock_version' => 2,
        ]);

        $this->actingAs($manager)->postJson(route('projects.risks.archive', [$project, $risk]), [
            'lock_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.id', $risk->id)
            ->assertJsonPath('data.lock_version', 3);
        $this->assertDatabaseHas('risks', ['id' => $risk->id]);
        $this->assertNotNull($risk->fresh()->archived_at);
        $this->actingAs($manager)->getJson(route('projects.risks.index', $project))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($manager)->getJson(route('projects.risks.index', [$project, 'archived' => 1]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('activity_logs', ['action' => 'risk.archived', 'subject_id' => $risk->id]);
        $this->actingAs($manager)->putJson(route('projects.risks.update', [$project, $risk]), [
            'title' => 'Archived records are read only',
            'probability' => 1,
            'impact' => 1,
            'status' => 'open',
            'lock_version' => 3,
        ])->assertForbidden();

        $this->actingAs($otherManager)->postJson(route('projects.risks.restore', [$otherProject, $risk]), [
            'lock_version' => 3,
        ])
            ->assertNotFound();
        $this->actingAs($manager)->postJson(route('projects.risks.restore', [$project, $risk]), [
            'lock_version' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->actingAs($manager)->postJson(route('projects.risks.restore', [$project, $risk]), [
            'lock_version' => 3,
        ])->assertOk()
            ->assertJsonPath('data.archived_at', null)
            ->assertJsonPath('data.lock_version', 4);
        $this->assertNull($risk->fresh()->archived_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'risk.restored', 'subject_id' => $risk->id]);
    }

    public function test_issue_requires_resolution_when_closed_and_supports_crud(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $base = [
            'title' => 'انقطاع بيئة الاختبار',
            'description' => 'البيئة غير متاحة.',
            'severity' => 'critical',
            'status' => 'resolved',
            'due_at' => '2026-08-16 12:00:00',
        ];

        $this->actingAs($manager)->postJson(route('projects.issues.store', $project), $base)
            ->assertUnprocessable()->assertJsonValidationErrors('resolution');

        $this->actingAs($manager)->postJson(route('projects.issues.store', $project), [
            ...$base,
            'status' => 'open',
        ])->assertCreated()->assertJsonPath('data.lock_version', 1);
        $issue = Issue::query()->firstOrFail();

        $this->actingAs($manager)->putJson(route('projects.issues.update', [$project, $issue]), [
            ...$base,
            'status' => 'resolved',
            'resolution' => 'إعادة تشغيل البيئة وتوثيق السبب.',
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.lock_version', 2);
        $this->actingAs($manager)->putJson(route('projects.issues.update', [$project, $issue]), [
            ...$base,
            'title' => 'كتابة قديمة لا تحفظ',
            'status' => 'closed',
            'resolution' => 'حل قديم',
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'title' => $base['title'],
            'status' => 'resolved',
            'lock_version' => 2,
        ]);
        $this->actingAs($manager)->postJson(route('projects.issues.archive', [$project, $issue]), [
            'lock_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.id', $issue->id)
            ->assertJsonPath('data.lock_version', 3);
        $this->assertDatabaseHas('issues', ['id' => $issue->id]);
        $this->assertNotNull($issue->fresh()->archived_at);
        $this->actingAs($manager)->getJson(route('projects.issues.index', $project))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($manager)->getJson(route('projects.issues.index', [$project, 'archived' => 1]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($manager)->postJson(route('projects.issues.restore', [$project, $issue]), [
            'lock_version' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('lock_version');
        $this->actingAs($manager)->postJson(route('projects.issues.restore', [$project, $issue]), [
            'lock_version' => 3,
        ])->assertOk()
            ->assertJsonPath('data.archived_at', null)
            ->assertJsonPath('data.lock_version', 4);
        $this->assertNull($issue->fresh()->archived_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'issue.archived', 'subject_id' => $issue->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'issue.restored', 'subject_id' => $issue->id]);
    }

    public function test_members_can_read_but_only_project_managers_can_mutate_governance(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $inactive = $this->makeUser('member', 'inactive');
        $outsider = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);
        $project->members()->attach($inactive, ['project_role' => 'manager', 'status' => 'active']);

        $this->actingAs($member)->getJson(route('projects.risks.index', $project))->assertOk();
        $this->actingAs($outsider)->getJson(route('projects.risks.index', $project))->assertForbidden();
        $this->actingAs($member)->postJson(route('projects.risks.store', $project), [
            'title' => 'غير مسموح',
            'probability' => 1,
            'impact' => 1,
            'status' => 'open',
        ])->assertForbidden();
        $this->actingAs($inactive)->getJson(route('projects.risks.index', $project))->assertForbidden();
    }

    public function test_project_workspace_hides_archived_governance_by_default_and_exposes_explicit_archive_view(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $archivedAt = now();
        $requirementStatus = $this->makeStatus('requirement', 'workspace-draft', 'open');
        Requirement::query()->create([
            'project_id' => $project->id,
            'code' => 'REQ-ACTIVE',
            'title' => 'Active requirement',
            'priority' => 'medium',
            'status_id' => $requirementStatus->id,
        ]);
        Requirement::query()->create([
            'project_id' => $project->id,
            'code' => 'REQ-ARCHIVED',
            'title' => 'Archived requirement',
            'priority' => 'medium',
            'status_id' => $requirementStatus->id,
            'archived_at' => $archivedAt,
        ]);
        Risk::query()->create([
            'project_id' => $project->id,
            'title' => 'Archived risk',
            'probability' => 2,
            'impact' => 2,
            'status' => 'open',
            'archived_at' => $archivedAt,
        ]);
        Issue::query()->create([
            'project_id' => $project->id,
            'title' => 'Archived issue',
            'severity' => 'medium',
            'status' => 'open',
            'archived_at' => $archivedAt,
        ]);
        $timeline = TimelineEntry::query()->create([
            'project_id' => $project->id,
            'kind' => 'meeting',
            'title' => 'Archived meeting',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'planned',
            'archived_at' => $archivedAt,
        ]);
        Meeting::query()->create([
            'timeline_entry_id' => $timeline->id,
            'organizer_id' => $manager->id,
            'archived_at' => $archivedAt,
        ]);

        $this->actingAs($manager)->get(route('projects.show', ['project' => $project, 'tab' => 'risks']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('governanceArchivedMode', false)
                ->has('project.requirements', 0)
                ->has('project.risks', 0)
                ->has('project.issues', 0)
                ->has('project.timeline_entries', 0));

        $this->actingAs($manager)->get(route('projects.show', [
            'project' => $project,
            'tab' => 'risks',
            'archived' => 1,
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('governanceArchivedMode', true)
                ->has('project.requirements', 0)
                ->has('project.risks', 1)
                ->has('project.issues', 0)
                ->has('project.timeline_entries', 0));

        $this->actingAs($manager)->get(route('projects.show', [
            'project' => $project,
            'tab' => 'requirements',
            'archived' => 1,
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('governanceArchivedMode', true)
                ->has('project.requirements', 1)
                ->where('project.requirements.0.code', 'REQ-ARCHIVED')
                ->where('project.requirements.0.can_update', false)
                ->where('project.requirements.0.can_archive', false)
                ->where('project.requirements.0.can_restore', true)
                ->has('project.risks', 0)
                ->has('project.issues', 0)
                ->has('project.timeline_entries', 0));

        $this->actingAs($manager)->get(route('projects.show', [
            'project' => $project,
            'tab' => 'timeline',
            'archived' => 1,
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('governanceArchivedMode', true)
                ->has('project.requirements', 0)
                ->has('project.risks', 0)
                ->has('project.issues', 0)
                ->has('project.timeline_entries', 1)
                ->where('project.timeline_entries.0.meeting.archived_at', fn ($value) => is_string($value)));
    }

    public function test_project_workspace_task_capabilities_match_manager_and_assignee_permissions(): void
    {
        $manager = $this->makeUser('project_manager');
        $assignee = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($assignee, ['project_role' => 'member', 'status' => 'active']);
        $status = $this->makeStatus('task', 'workspace-capability-open', 'open');
        Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TSK-WORKSPACE-CAPABILITY',
            'title' => 'Workspace capability task',
            'status_id' => $status->id,
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
            'assigned_at' => now(),
            'start_at' => now(),
            'due_at' => now()->addDay(),
        ]);

        $this->actingAs($manager)->get(route('projects.show', ['project' => $project, 'tab' => 'tasks']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.tasks.0.can_update', true)
                ->where('project.tasks.0.can_update_status', true));

        $this->actingAs($assignee)->get(route('projects.show', ['project' => $project, 'tab' => 'tasks']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('project.tasks.0.can_update', false)
                ->where('project.tasks.0.can_update_status', true));
    }
}
