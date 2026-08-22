<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationCenterService;
use App\Services\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public function edit(
        Request $request,
        SystemSettingsService $settings,
        NotificationCenterService $notifications,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('settings/notifications', [
            'preferences' => $notifications->personalPreferences($user),
            'systemPolicy' => $settings->group('notifications'),
        ]);
    }

    public function update(
        NotificationPreferencesRequest $request,
        ActivityLogger $activityLogger,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $before = $user->notification_preferences ?? [];
        $preferences = $request->validated();
        $user->forceFill(['notification_preferences' => $preferences])->save();

        $activityLogger->record(
            $user,
            'notification_preferences.updated',
            $user,
            ['notification_preferences' => $before],
            ['notification_preferences' => $preferences],
            $request,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'حُفظت تفضيلات التنبيهات الشخصية.',
        ]);

        return to_route('notification-preferences.edit');
    }
}
