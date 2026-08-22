<?php

namespace App\Http\Middleware;

use App\Models\DataJob;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\NotificationCenterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'canCreateTask' => fn (): bool => $this->canCreateTask($request),
            'abilities' => fn (): array => [
                'viewDataCenter' => $request->user()?->can('viewAny', DataJob::class) ?? false,
                'viewSettings' => $request->user()?->can('viewAny', SystemSetting::class) ?? false,
            ],
            'notifications' => fn (): array => $this->notifications($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'localization' => $request->attributes->get('project_desk.localization'),
        ];
    }

    private function canCreateTask(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User || $user->status !== 'active' || $user->archived_at !== null) {
            return false;
        }

        $projects = Project::query()->visibleTo($user)->whereNull('archived_at');
        if ($user->global_role === 'admin') {
            return $projects->exists();
        }

        return $projects
            ->where(function (Builder $query) use ($user): void {
                $query->where('manager_id', $user->id)
                    ->orWhereHas('members', fn (Builder $members) => $members
                        ->whereKey($user->id)
                        ->where('project_members.project_role', 'manager')
                        ->where('project_members.status', 'active'));
            })
            ->exists();
    }

    /** @return array<string, mixed> */
    private function notifications(Request $request): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return [
                'enabled' => false,
                'count' => 0,
                'lead_hours' => 24,
                'items' => [],
            ];
        }

        return app(NotificationCenterService::class)->for($user);
    }
}
