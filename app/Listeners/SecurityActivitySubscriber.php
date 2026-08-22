<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

class SecurityActivitySubscriber
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly Request $request,
    ) {}

    public function handleLogin(Login $event): void
    {
        $this->record($event->user, 'security.login_succeeded', [
            'guard' => (string) $event->guard,
            'remembered' => (bool) $event->remember,
        ], actor: true);
    }

    public function handleLogout(Logout $event): void
    {
        $this->record($event->user, 'security.logout', [
            'guard' => (string) $event->guard,
        ], actor: true);
    }

    public function handleFailed(Failed $event): void
    {
        // Never persist credentials. Unknown accounts deliberately produce no
        // user-scoped record, while rate-limit and web-server logs retain the
        // anonymous attempt without introducing a synthetic domain subject.
        $this->record($event->user, 'security.login_failed', [
            'guard' => (string) $event->guard,
        ]);
    }

    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        $this->record($event->user, 'security.two_factor_enabled', [], actor: true);
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record($event->user, 'security.two_factor_confirmed', [], actor: true);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record($event->user, 'security.two_factor_disabled', [], actor: true);
    }

    public function handleTwoFactorChallenged(TwoFactorAuthenticationChallenged $event): void
    {
        $this->record($event->user, 'security.two_factor_challenged');
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record($event->user, 'security.two_factor_failed');
    }

    public function handleTwoFactorVerified(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $this->record($event->user, 'security.two_factor_verified', [], actor: true);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->record($event->user, 'security.recovery_codes_generated', [], actor: true);
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        $this->record($event->user, 'security.recovery_code_used', [], actor: true);
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->record($event->user, 'security.passkey_registered', [
            'passkey_id' => (int) $event->passkey->getKey(),
        ], actor: true);
    }

    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->record($event->user, 'security.passkey_deleted', [
            'passkey_id' => (int) $event->passkey->getKey(),
        ], actor: true);
    }

    public function handlePasskeyVerified(PasskeyVerified $event): void
    {
        $this->record($event->user, 'security.passkey_verified', [
            'passkey_id' => (int) $event->passkey->getKey(),
        ], actor: true);
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(Failed::class, [self::class, 'handleFailed']);
        $events->listen(TwoFactorAuthenticationEnabled::class, [self::class, 'handleTwoFactorEnabled']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
        $events->listen(TwoFactorAuthenticationChallenged::class, [self::class, 'handleTwoFactorChallenged']);
        $events->listen(TwoFactorAuthenticationFailed::class, [self::class, 'handleTwoFactorFailed']);
        $events->listen(ValidTwoFactorAuthenticationCodeProvided::class, [self::class, 'handleTwoFactorVerified']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
        $events->listen(RecoveryCodeReplaced::class, [self::class, 'handleRecoveryCodeReplaced']);
        $events->listen(PasskeyRegistered::class, [self::class, 'handlePasskeyRegistered']);
        $events->listen(PasskeyDeleted::class, [self::class, 'handlePasskeyDeleted']);
        $events->listen(PasskeyVerified::class, [self::class, 'handlePasskeyVerified']);
    }

    /** @param array<string, bool|int|string> $context */
    private function record(
        mixed $subject,
        string $action,
        array $context = [],
        bool $actor = false,
    ): void {
        if (! $subject instanceof User) {
            return;
        }

        $this->activityLogger->record(
            $subject,
            $action,
            $actor ? $subject : null,
            after: $context,
            request: $this->request,
        );
    }
}
