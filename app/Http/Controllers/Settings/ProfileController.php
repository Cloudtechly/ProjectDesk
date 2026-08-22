<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\UserSessionSecurity;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(
        ProfileUpdateRequest $request,
        UserSessionSecurity $sessionSecurity,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $beforeEmail = $user->email;
        $user->fill($request->safe()->only(['name', 'email']));
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();
        if ($emailChanged) {
            $sessionSecurity->invalidateFor($user, $request->session()->getId());
            $user->sendEmailVerificationNotification();
            $activityLogger->record(
                $user,
                'security.email_changed',
                $user,
                before: ['email' => $beforeEmail],
                after: [
                    'email' => $user->email,
                    'email_verified' => false,
                    'other_sessions_invalidated' => true,
                    'remember_token_rotated' => true,
                ],
                request: $request,
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
