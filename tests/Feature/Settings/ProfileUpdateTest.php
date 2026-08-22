<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_changing_email_requires_verification_before_returning_to_dashboard(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'changed-email@example.test',
                'current_password' => 'password',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertNull($user->refresh()->email_verified_at);
        $this->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_changing_email_requires_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new-email@example.test',
                'current_password' => 'password',
            ])
            ->assertRedirect(route('password.confirm'));

        self::assertNotSame('new-email@example.test', $user->fresh()->email);
    }

    public function test_changing_email_requires_current_password_after_recent_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new-email@example.test',
            ])
            ->assertSessionHasErrors('current_password');

        self::assertNotSame('new-email@example.test', $user->fresh()->email);
    }

    public function test_changing_email_rejects_an_incorrect_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new-email@example.test',
                'current_password' => 'incorrect-password',
            ])
            ->assertSessionHasErrors('current_password');

        self::assertNotSame('new-email@example.test', $user->fresh()->email);
    }

    public function test_verified_email_change_sends_verification_and_revokes_other_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $rememberToken = $user->remember_token;
        DB::table('sessions')->insert([
            'id' => 'other-profile-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'profile-email-security-test',
            'payload' => 'test',
            'last_activity' => time(),
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'secured-email@example.test',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        self::assertSame('secured-email@example.test', $user->email);
        self::assertNull($user->email_verified_at);
        self::assertNotSame($rememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-profile-session']);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'security.email_changed',
            'subject_id' => $user->id,
            'actor_id' => $user->id,
        ]);
    }

    public function test_profile_deletion_route_is_not_exposed()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/settings/profile')->assertMethodNotAllowed();
        $this->assertNotNull($user->fresh());
    }
}
