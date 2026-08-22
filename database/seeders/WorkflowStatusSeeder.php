<?php

namespace Database\Seeders;

use App\Models\WorkflowStatus;
use Illuminate\Database\Seeder;

class WorkflowStatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['project', 'planning', 'تخطيط', 'open', '#64717D', 10],
            ['project', 'active', 'قيد التنفيذ', 'in_progress', '#406386', 20],
            ['project', 'completed', 'مكتمل', 'done', '#137A45', 30],
            ['project', 'cancelled', 'ملغي', 'cancelled', '#64717D', 40],
            ['task', 'new', 'جديدة', 'open', '#64717D', 10],
            ['task', 'in_progress', 'قيد التنفيذ', 'in_progress', '#406386', 20],
            ['task', 'review', 'مراجعة', 'in_progress', '#8A5700', 30],
            ['task', 'completed', 'مكتملة', 'done', '#137A45', 40],
            ['task', 'cancelled', 'ملغاة', 'cancelled', '#64717D', 50],
            ['requirement', 'draft', 'مسودة', 'open', '#64717D', 10],
            ['requirement', 'approved', 'معتمد', 'in_progress', '#406386', 20],
            ['requirement', 'delivered', 'مُسلّم', 'done', '#137A45', 30],
        ];

        foreach ($statuses as [$entityType, $code, $label, $semantic, $color, $position]) {
            WorkflowStatus::query()->updateOrCreate(
                ['entity_type' => $entityType, 'code' => $code],
                compact('label', 'semantic', 'color', 'position') + ['is_active' => true],
            );
        }
    }
}
