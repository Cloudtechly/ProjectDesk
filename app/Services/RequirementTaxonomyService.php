<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementCategory;
use App\Models\RequirementGroup;
use App\Models\RequirementRelation;
use App\Models\TaxonomyTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequirementTaxonomyService
{
    public const TYPES = ['functional', 'technical', 'non_functional', 'security', 'data', 'integration', 'business'];

    public const RELATIONS = ['depends_on', 'complements', 'details', 'conflicts_with', 'duplicates', 'replaces', 'related_to'];

    /** @return array<string, mixed> */
    public function tree(Project $project): array
    {
        $categories = $project->requirementCategories()->with([
            'groups' => fn ($query) => $query->with(['requirements' => fn ($requirements) => $requirements
                ->whereNull('archived_at')->withCount('tasks')->with([
                    'status:id,label,semantic,color', 'outgoingRelations.target:id,code,title',
                    'incomingRelations.source:id,code,title',
                ])->orderBy('code')]),
        ])->orderBy('position')->get();
        $uncategorized = $project->requirements()->whereNull('group_id')->whereNull('archived_at')
            ->withCount('tasks')->with([
                'status:id,label,semantic,color', 'outgoingRelations.target:id,code,title',
                'incomingRelations.source:id,code,title',
            ])->orderBy('code')->get();

        return [
            'categories' => $categories,
            'uncategorized' => [
                'name' => __('Uncategorized'),
                'requirements' => $uncategorized,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public function createCategory(Project $project, array $data): RequirementCategory
    {
        return $project->requirementCategories()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function createGroup(Project $project, RequirementCategory $category, array $data): RequirementGroup
    {
        if ($category->project_id !== $project->id) {
            abort(404);
        }

        return $category->groups()->create([...$data, 'project_id' => $project->id]);
    }

    public function mergeGroups(Project $project, RequirementGroup $source, RequirementGroup $target): RequirementGroup
    {
        if ($source->project_id !== $project->id || $target->project_id !== $project->id || $source->is($target)) {
            throw ValidationException::withMessages(['target_group_id' => 'يجب اختيار مجموعة أخرى من المشروع نفسه.']);
        }

        return DB::transaction(function () use ($source, $target): RequirementGroup {
            $locked = RequirementGroup::query()->lockForUpdate()->whereKey([$source->id, $target->id])->get()->keyBy('id');
            $lockedSource = $locked->get($source->id);
            $lockedTarget = $locked->get($target->id);
            if (! $lockedSource instanceof RequirementGroup || ! $lockedTarget instanceof RequirementGroup) {
                throw ValidationException::withMessages(['target_group_id' => 'تعذر العثور على إحدى المجموعتين.']);
            }
            Requirement::query()->where('group_id', $lockedSource->id)->update(['group_id' => $lockedTarget->id]);
            $lockedSource->delete();

            return $lockedTarget->fresh(['category', 'requirements']);
        });
    }

    public function relate(Project $project, Requirement $source, Requirement $target, string $type, User $actor): RequirementRelation
    {
        if ($source->project_id !== $project->id || $target->project_id !== $project->id) {
            throw ValidationException::withMessages(['target_requirement_id' => 'يجب أن يكون المتطلبان داخل المشروع نفسه.']);
        }
        if ($source->is($target)) {
            throw ValidationException::withMessages(['target_requirement_id' => 'لا يمكن ربط المتطلب بنفسه.']);
        }
        if (! in_array($type, self::RELATIONS, true)) {
            throw ValidationException::withMessages(['type' => 'نوع العلاقة غير معروف.']);
        }
        if ($type === 'depends_on' && $this->dependencyPathExists($target->id, $source->id, $project->id)) {
            throw ValidationException::withMessages(['target_requirement_id' => 'هذه العلاقة تنشئ دورة اعتماد غير مسموحة.']);
        }

        return RequirementRelation::query()->firstOrCreate([
            'source_requirement_id' => $source->id,
            'target_requirement_id' => $target->id,
            'type' => $type,
        ], ['project_id' => $project->id, 'created_by' => $actor->id]);
    }

    /** @return array<string, mixed> */
    public function applyTemplate(Project $project, TaxonomyTemplate $template): array
    {
        return DB::transaction(function () use ($project, $template): array {
            $tree = $template->getAttribute('tree');
            if (! is_array($tree)) {
                throw ValidationException::withMessages(['template' => 'قالب التصنيف غير صالح.']);
            }
            foreach ($tree as $categoryPosition => $categoryData) {
                if (! is_array($categoryData) || ! isset($categoryData['name'])) {
                    continue;
                }
                $category = $project->requirementCategories()->firstOrCreate(
                    ['name' => (string) $categoryData['name']],
                    ['description' => $categoryData['description'] ?? null, 'position' => $categoryPosition],
                );
                $groups = $categoryData['groups'] ?? [];
                if (! is_array($groups)) {
                    continue;
                }
                foreach ($groups as $groupPosition => $groupData) {
                    $name = is_array($groupData) ? ($groupData['name'] ?? null) : $groupData;
                    if (! is_string($name) || trim($name) === '') {
                        continue;
                    }
                    $category->groups()->firstOrCreate(['name' => $name], [
                        'project_id' => $project->id, 'position' => $groupPosition,
                        'description' => is_array($groupData) ? ($groupData['description'] ?? null) : null,
                    ]);
                }
            }

            return $this->tree($project);
        });
    }

    private function dependencyPathExists(int $from, int $target, int $projectId): bool
    {
        $visited = [];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $target) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $queue = [...$queue, ...RequirementRelation::query()
                ->where('project_id', $projectId)->where('type', 'depends_on')
                ->where('source_requirement_id', $current)->pluck('target_requirement_id')->map(fn ($id) => (int) $id)->all()];
        }

        return false;
    }
}
