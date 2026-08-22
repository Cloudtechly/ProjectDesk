<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\WorkflowStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_install_can_seed_workflows_and_provision_first_admin(): void
    {
        $this->seed(WorkflowStatusSeeder::class);

        $this->artisan('project-desk:provision-admin', [
            '--name' => 'مدير النظام',
            '--email' => 'first.admin@example.com',
            '--password' => 'ProjectDesk-2026!',
        ])->assertSuccessful();

        $this->assertDatabaseHas('workflow_statuses', ['entity_type' => 'project', 'is_active' => true]);
        $this->assertDatabaseHas('workflow_statuses', ['entity_type' => 'task', 'is_active' => true]);
        $this->assertDatabaseHas('users', [
            'email' => 'first.admin@example.com',
            'global_role' => 'admin',
            'status' => 'active',
        ]);
        $this->assertNotNull(User::query()->where('email', 'first.admin@example.com')->value('email_verified_at'));

        $this->artisan('project-desk:provision-admin')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
    }
}
