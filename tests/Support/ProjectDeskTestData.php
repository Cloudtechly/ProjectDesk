<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowStatus;

trait ProjectDeskTestData
{
    protected function makeUser(string $role = 'admin', string $status = 'active'): User
    {
        return User::factory()->create(['global_role' => $role, 'status' => $status]);
    }

    protected function makeStatus(string $entityType, string $code, string $semantic): WorkflowStatus
    {
        return WorkflowStatus::query()->create([
            'entity_type' => $entityType,
            'code' => $code,
            'label' => $code,
            'semantic' => $semantic,
            'color' => '#406386',
            'position' => 10,
            'is_active' => true,
        ]);
    }

    protected function makeProject(User $manager, ?WorkflowStatus $status = null): Project
    {
        $status ??= $this->makeStatus('project', 'active', 'in_progress');
        $client = Client::query()->create([
            'created_by' => $manager->id,
            'code' => 'CL-'.fake()->unique()->numerify('#####'),
            'name' => fake()->company(),
            'status' => 'active',
        ]);
        $project = Project::query()->create([
            'code' => 'PRJ-'.fake()->unique()->numerify('#####'),
            'name' => fake()->sentence(3),
            'client_id' => $client->id,
            'manager_id' => $manager->id,
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        $project->members()->attach($manager, ['project_role' => 'manager', 'status' => 'active']);
        $project->refresh();

        return $project;
    }
}
