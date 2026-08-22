<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationCenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class OpenNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        string $notification,
        NotificationCenterService $notifications,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var DatabaseNotification $record */
        $record = $user->notifications()
            ->whereKey($notification)
            ->where('type', NotificationCenterService::TYPE)
            ->firstOrFail();

        $destination = $notifications->destination($user, $record);
        if ($destination === null) {
            $record->delete();

            return to_route('dashboard')->with(
                'warning',
                'أُلغي هذا التنبيه لأن السجل لم يعد متاحاً أو لم تعد لديك صلاحية الوصول إليه.',
            );
        }

        $record->markAsRead();

        return redirect()->to($destination);
    }
}
