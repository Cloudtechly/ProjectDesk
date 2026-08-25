<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\Requirement;
use App\Models\RequirementCandidate;
use App\Models\RequirementCategory;
use App\Models\RequirementGroup;
use App\Models\RequirementSource;
use App\Models\Risk;
use App\Models\User;
use App\Models\WorkflowStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequirementCandidateApprovalService
{
    public function __construct(private readonly RequirementTaxonomyService $taxonomy) {}

    /**
     * @param  list<array<string, mixed>>  $decisions
     * @return array<string, mixed>
     */
    public function decide(int $runId, array $decisions, User $actor): array
    {
        return DB::transaction(function () use ($runId, $decisions, $actor): array {
            $candidates = RequirementCandidate::query()->where('analysis_run_id', $runId)
                ->whereIn('id', collect($decisions)->pluck('id'))->lockForUpdate()->get()->keyBy('id');
            if ($candidates->count() !== count($decisions)) {
                throw ValidationException::withMessages(['candidates' => 'تحتوي الدفعة على نتائج غير صالحة.']);
            }
            $approved = [];
            foreach ($decisions as $decision) {
                $candidate = $candidates->get((int) $decision['id']);
                if (! $candidate instanceof RequirementCandidate || $candidate->status !== 'pending') {
                    continue;
                }
                $action = (string) $decision['action'];
                if (in_array($action, ['approve', 'edit_approve'], true)) {
                    if ($candidate->change_type === 'deleted') {
                        $candidate->update(['status' => 'acknowledged', 'decided_by' => $actor->id, 'decided_at' => now()]);

                        continue;
                    }
                    $approved[$candidate->candidate_key] = $this->approve($candidate, (array) ($decision['changes'] ?? []), $actor);
                } elseif ($action === 'merge') {
                    $target = Requirement::query()->where('project_id', $candidate->analysisRun->project_id)->findOrFail((int) $decision['target_requirement_id']);
                    $this->attachSource($candidate, $target);
                    $candidate->update(['status' => 'merged', 'approved_requirement_id' => $target->id, 'decided_by' => $actor->id, 'decided_at' => now()]);
                } elseif ($action === 'question') {
                    Issue::query()->create(['project_id' => $candidate->analysisRun->project_id, 'title' => $candidate->title,
                        'description' => implode("\n", $this->stringList($candidate->getAttribute('ambiguities'))), 'severity' => 'medium', 'status' => 'open', 'lock_version' => 1]);
                    $candidate->update(['status' => 'converted_to_question', 'decided_by' => $actor->id, 'decided_at' => now()]);
                } elseif ($action === 'risk') {
                    Risk::query()->create(['project_id' => $candidate->analysisRun->project_id, 'title' => $candidate->title,
                        'description' => $candidate->description, 'probability' => 2, 'impact' => 2, 'status' => 'open', 'lock_version' => 1]);
                    $candidate->update(['status' => 'converted_to_risk', 'decided_by' => $actor->id, 'decided_at' => now()]);
                } else {
                    $candidate->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now()]);
                }
            }
            $this->createRelations($candidates, $approved, $actor);
            $run = $candidates->first()?->analysisRun;
            if ($run && ! $run->candidates()->where('status', 'pending')->exists()) {
                $run->update(['status' => 'approved', 'finished_at' => now()]);
            }

            return ['approved_requirement_ids' => collect($approved)->pluck('id')->all(), 'run_status' => $run?->fresh()->status];
        });
    }

    /** @param array<string, mixed> $changes */
    private function approve(RequirementCandidate $candidate, array $changes, User $actor): Requirement
    {
        $run = $candidate->analysisRun;
        $categoryName = (string) ($changes['category_name'] ?? $candidate->category_name);
        $groupName = (string) ($changes['group_name'] ?? $candidate->group_name);
        $category = RequirementCategory::query()->firstOrCreate(['project_id' => $run->project_id, 'name' => $categoryName]);
        $group = RequirementGroup::query()->firstOrCreate(['category_id' => $category->id, 'name' => $groupName], ['project_id' => $run->project_id]);
        $status = WorkflowStatus::query()->where('entity_type', 'requirement')->where('is_active', true)->orderBy('position')->firstOrFail();
        $existing = $candidate->matchedRequirement;
        $data = [
            'project_id' => $run->project_id, 'group_id' => $group->id,
            'title' => (string) ($changes['title'] ?? $candidate->title),
            'description' => $changes['description'] ?? $candidate->description,
            'acceptance_criteria' => implode("\n", $this->stringList($changes['acceptance_criteria'] ?? $candidate->getAttribute('acceptance_criteria'))),
            'type' => (string) ($changes['type'] ?? $candidate->type),
            'priority' => (string) ($changes['priority'] ?? $candidate->priority),
        ];
        if ($existing instanceof Requirement && in_array($candidate->change_type, ['modified', 'unchanged'], true)) {
            if ($candidate->change_type === 'modified') {
                $existing->update([...$data, 'lock_version' => $existing->lock_version + 1]);
            }
            $requirement = $existing;
        } else {
            $requirement = Requirement::query()->create([...$data, 'code' => 'PENDING-'.$candidate->candidate_key, 'status_id' => $status->id, 'lock_version' => 1]);
            $requirement->forceFill(['code' => 'REQ-'.str_pad((string) $requirement->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
        }
        $this->attachSource($candidate, $requirement);
        $candidate->update(['status' => 'approved', 'approved_requirement_id' => $requirement->id, 'decided_by' => $actor->id, 'decided_at' => now()]);

        return $requirement;
    }

    private function attachSource(RequirementCandidate $candidate, Requirement $requirement): void
    {
        RequirementSource::query()->firstOrCreate([
            'requirement_id' => $requirement->id,
            'requirement_book_version_id' => $candidate->analysisRun->requirement_book_version_id,
            'locator_type' => $candidate->source_locator_type, 'locator' => $candidate->source_locator,
        ], ['analysis_run_id' => $candidate->analysis_run_id, 'excerpt' => $candidate->source_excerpt, 'confidence' => $candidate->confidence]);
    }

    /**
     * @param  Collection<int|string, RequirementCandidate>  $candidates
     * @param  array<string, Requirement>  $approved
     */
    private function createRelations(Collection $candidates, array $approved, User $actor): void
    {
        foreach ($candidates as $candidate) {
            $source = $approved[$candidate->candidate_key] ?? null;
            if (! $source instanceof Requirement) {
                continue;
            }
            $relations = $candidate->getAttribute('relations');
            if (! is_array($relations)) {
                continue;
            }
            foreach ($relations as $relation) {
                if (! is_array($relation) || ! isset($relation['target_title'], $relation['type'])) {
                    continue;
                }
                $targetCandidate = $candidates->first(fn (RequirementCandidate $item): bool => mb_strtolower($item->title) === mb_strtolower((string) $relation['target_title']));
                $target = $targetCandidate ? ($approved[$targetCandidate->candidate_key] ?? $targetCandidate->matchedRequirement) : null;
                if ($target instanceof Requirement) {
                    $this->taxonomy->relate($source->project, $source, $target, (string) $relation['type'], $actor);
                }
            }
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
