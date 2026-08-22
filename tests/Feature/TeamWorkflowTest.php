<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class TeamWorkflowTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_admin_can_create_archive_and_restore_a_member_without_deleting_relations(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->post(route('team.store'), [
            'name' => 'عضو جديد',
            'email' => 'new.member@example.com',
            'phone' => '+218900000001',
            'job_title' => 'مطوّر',
            'global_role' => 'member',
            'status' => 'active',
            'password' => 'ProjectDesk-2026!',
            'password_confirmation' => 'ProjectDesk-2026!',
        ])->assertRedirect();

        $member = User::query()->where('email', 'new.member@example.com')->firstOrFail();
        $project = $this->makeProject($admin);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);

        $this->actingAs($admin)->post(route('team.archive', $member))->assertRedirect();
        $member->refresh();
        $this->assertNotNull($member->archived_at);
        $this->assertSame('inactive', $member->status);
        $this->assertTrue($project->members()->whereKey($member->id)->exists());

        $this->actingAs($admin)->post(route('team.restore', $member))->assertRedirect();
        $member->refresh();
        $this->assertNull($member->archived_at);
        $this->assertSame('active', $member->status);
    }

    public function test_non_admin_cannot_manage_team_accounts(): void
    {
        $manager = $this->makeUser('project_manager');

        $this->actingAs($manager)->post(route('team.store'), [
            'name' => 'غير مسموح',
            'email' => 'denied@example.com',
            'global_role' => 'member',
            'status' => 'active',
            'password' => 'ProjectDesk-2026!',
            'password_confirmation' => 'ProjectDesk-2026!',
        ])->assertForbidden();
    }

    public function test_manager_does_not_see_member_with_inactive_project_membership(): void
    {
        $manager = $this->makeUser('project_manager');
        $visible = $this->makeUser('member');
        $inactive = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($visible, ['project_role' => 'member', 'status' => 'active']);
        $project->members()->attach($inactive, ['project_role' => 'member', 'status' => 'inactive']);

        $this->actingAs($manager)
            ->get(route('team.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('members', 2)
                ->where('members.0.id', fn ($id) => in_array($id, [$manager->id, $visible->id], true))
                ->where('members.1.id', fn ($id) => in_array($id, [$manager->id, $visible->id], true)));

        $this->actingAs($manager)
            ->get(route('projects.show', ['project' => $project, 'tab' => 'team']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('project.members', 2)
                ->where('project.members.0.id', fn ($id) => in_array($id, [$manager->id, $visible->id], true))
                ->where('project.members.1.id', fn ($id) => in_array($id, [$manager->id, $visible->id], true)));
    }

    public function test_sensitive_self_update_requires_recent_and_current_password_then_revokes_sessions(): void
    {
        Notification::fake();
        $admin = $this->makeUser('admin');
        $oldRememberToken = $admin->remember_token;
        DB::table('sessions')->insert([
            'id' => 'old-admin-session',
            'user_id' => $admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'security-test',
            'payload' => 'test',
            'last_activity' => time(),
        ]);
        $payload = [
            'name' => $admin->name,
            'email' => 'rotated-admin@example.com',
            'phone' => $admin->phone,
            'job_title' => $admin->job_title,
            'global_role' => 'admin',
            'status' => 'active',
            'password' => 'New-ProjectDesk-2026!',
            'password_confirmation' => 'New-ProjectDesk-2026!',
        ];

        $this->actingAs($admin)->putJson(route('team.update', $admin), $payload)->assertStatus(423);
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->putJson(route('team.update', $admin), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->putJson(route('team.update', $admin), [...$payload, 'current_password' => 'password'])
            ->assertRedirect();

        $admin->refresh();
        self::assertSame('rotated-admin@example.com', $admin->email);
        self::assertNull($admin->email_verified_at);
        self::assertTrue(Hash::check('New-ProjectDesk-2026!', $admin->password));
        self::assertNotSame($oldRememberToken, $admin->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-admin-session']);
        Notification::assertSentTo($admin, VerifyEmail::class);
    }
}
