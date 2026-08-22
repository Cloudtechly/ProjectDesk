<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

class SecurityActivityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_events_are_audited_without_credentials(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, true));
        event(new Failed('web', $user, [
            'email' => $user->email,
            'password' => 'never-persist-this-secret',
        ]));
        event(new Logout('web', $user));

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'action' => 'security.login_succeeded',
            'actor_id' => $user->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'action' => 'security.login_failed',
            'actor_id' => null,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'action' => 'security.logout',
            'actor_id' => $user->id,
        ]);

        $serializedAudit = DB::table('activity_logs')
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->get(['before', 'after'])
            ->toJson();
        $this->assertStringNotContainsString('never-persist-this-secret', $serializedAudit);
        $this->assertStringNotContainsString($user->email, $serializedAudit);
    }

    public function test_two_factor_lifecycle_is_audited_without_secrets(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationEnabled($user));
        event(new TwoFactorAuthenticationFailed($user));
        event(new TwoFactorAuthenticationDisabled($user));

        $this->assertSame(
            [
                'security.two_factor_enabled',
                'security.two_factor_failed',
                'security.two_factor_disabled',
            ],
            DB::table('activity_logs')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertDatabaseCount('activity_logs', 3);
    }

    public function test_unknown_account_failure_does_not_create_a_synthetic_subject_or_store_credentials(): void
    {
        event(new Failed('web', null, [
            'email' => 'unknown@example.test',
            'password' => 'never-persist-this-secret',
        ]));

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_passkey_lifecycle_is_audited_without_credential_material(): void
    {
        $user = User::factory()->create();
        $passkey = (new Passkey)->forceFill([
            'id' => 42,
            'credential_id' => 'secret-credential-id',
            'credential' => ['publicKey' => 'secret-public-key'],
        ]);

        event(new PasskeyRegistered($user, $passkey));
        event(new PasskeyVerified($user, $passkey));
        event(new PasskeyDeleted($user, $passkey));

        $this->assertSame(
            [
                'security.passkey_registered',
                'security.passkey_verified',
                'security.passkey_deleted',
            ],
            DB::table('activity_logs')->orderBy('id')->pluck('action')->all(),
        );
        $serializedAudit = DB::table('activity_logs')->get(['before', 'after'])->toJson();
        $this->assertStringContainsString('passkey_id', $serializedAudit);
        $this->assertStringNotContainsString('secret-credential-id', $serializedAudit);
        $this->assertStringNotContainsString('secret-public-key', $serializedAudit);
    }
}
