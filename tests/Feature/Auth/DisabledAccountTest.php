<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisabledAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_or_archived_account_cannot_log_in(): void
    {
        $inactive = User::factory()->create(['email' => 'inactive@example.com', 'status' => 'inactive']);
        $archived = User::factory()->create(['email' => 'archived@example.com', 'status' => 'active', 'archived_at' => now()]);

        foreach ([$inactive, $archived] as $user) {
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    public function test_account_disabled_during_session_is_logged_out_and_denied(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $user->update(['status' => 'inactive']);

        $this->get(route('dashboard'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_disabled_session_cannot_reach_fortify_security_endpoints(): void
    {
        $endpoints = [
            ['get', route('password.confirm')],
            ['get', route('two-factor.recovery-codes')],
            ['get', route('passkey.registration-options')],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $user = User::factory()->create(['status' => 'inactive']);

            $this->actingAs($user)->{$method}($uri)->assertForbidden();
            $this->assertGuest();
        }
    }
}
