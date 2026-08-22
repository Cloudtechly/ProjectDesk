<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\UserSessionSecurity;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private readonly UserSessionSecurity $sessionSecurity,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();
        $this->sessionSecurity->invalidateFor($user);
        $this->activityLogger->record(
            $user,
            'security.password_reset',
            null,
            after: ['sessions_invalidated' => true, 'remember_token_rotated' => true],
            request: request(),
        );
    }
}
