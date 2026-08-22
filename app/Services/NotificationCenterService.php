<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type NotificationData array{
 *     source_type: 'task'|'meeting',
 *     source_id: int,
 *     tone: 'danger'|'warning'|'info',
 *     label: string,
 *     title: string,
 *     project: string,
 *     project_code: string,
 *     scheduled_at: string,
 *     fingerprint: string
 * }
 * @phpstan-type NotificationItem array{
 *     id: string,
 *     type: 'task'|'meeting',
 *     tone: 'danger'|'warning'|'info',
 *     label: string,
 *     title: string,
 *     project: string,
 *     project_code: string,
 *     scheduled_at: string,
 *     open_url: string
 * }
 */
class NotificationCenterService
{
    public const TYPE = 'project-desk.deadline';

    private const ITEM_LIMIT = 12;

    public function __construct(private readonly SystemSettingsService $settings) {}

    /**
     * Synchronize the database notification inbox with current business data.
     * Stable IDs make repeated scheduler runs idempotent, while the final
     * cleanup removes notifications whose source, policy, or authorization is
     * no longer valid.
     *
     * @return array{created: int, updated: int, deleted: int, users: int}
     */
    public function sync(): array
    {
        $systemPreferences = $this->systemPreferences();
        $summary = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'users' => 0];

        if (! $systemPreferences['enabled']) {
            $summary['deleted'] = DatabaseNotification::query()
                ->where('type', self::TYPE)
                ->delete();

            return $summary;
        }

        $activeUserIds = User::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->pluck('id');

        User::query()
            ->whereKey($activeUserIds)
            ->orderBy('id')
            ->chunkById(100, function (Collection $users) use (&$summary, $systemPreferences): void {
                foreach ($users as $user) {
                    $summary['users']++;
                    $this->syncUser(
                        $user,
                        $this->effectivePreferences($user, $systemPreferences),
                        $summary,
                    );
                }
            });

        $inactiveNotifications = DatabaseNotification::query()
            ->where('type', self::TYPE)
            ->where('notifiable_type', User::class);

        if ($activeUserIds->isEmpty()) {
            $summary['deleted'] += $inactiveNotifications->delete();
        } else {
            $summary['deleted'] += $inactiveNotifications
                ->whereNotIn('notifiable_id', $activeUserIds)
                ->delete();
        }

        return $summary;
    }

    /**
     * Return only unread persisted notifications. The scheduler is the sole
     * producer, so opening a notification keeps it read across page loads.
     *
     * @return array{
     *     enabled: bool,
     *     count: int,
     *     lead_hours: int,
     *     items: list<NotificationItem>
     * }
     */
    public function for(User $user): array
    {
        $systemPreferences = $this->systemPreferences();
        $preferences = $this->effectivePreferences($user, $systemPreferences);

        if (! $preferences['enabled']
            || $user->status !== 'active'
            || $user->archived_at !== null) {
            return $this->emptyFeed(false, $preferences['lead_hours']);
        }

        $notifications = $user->unreadNotifications()
            ->where('type', self::TYPE)
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => $this->matchesPreferences(
                $notification->data,
                $preferences,
            ))
            ->sort(function (DatabaseNotification $left, DatabaseNotification $right): int {
                /** @var NotificationData $leftData */
                $leftData = $left->data;
                /** @var NotificationData $rightData */
                $rightData = $right->data;

                return [
                    $this->toneOrder($leftData['tone']),
                    $leftData['scheduled_at'],
                    $left->id,
                ] <=> [
                    $this->toneOrder($rightData['tone']),
                    $rightData['scheduled_at'],
                    $right->id,
                ];
            })
            ->values();

        return [
            'enabled' => true,
            'count' => $notifications->count(),
            'lead_hours' => $preferences['lead_hours'],
            'items' => array_values($notifications
                ->take(self::ITEM_LIMIT)
                ->map(function (DatabaseNotification $notification): array {
                    /** @var NotificationData $data */
                    $data = $notification->data;

                    return [
                        'id' => $notification->id,
                        'type' => $data['source_type'],
                        'tone' => $data['tone'],
                        'label' => $data['label'],
                        'title' => $data['title'],
                        'project' => $data['project'],
                        'project_code' => $data['project_code'],
                        'scheduled_at' => $data['scheduled_at'],
                        'open_url' => route('notifications.open', $notification, false),
                    ];
                })
                ->all()),
        ];
    }

    /**
     * Personal choices default to enabled and may only shorten the system
     * lead window. They cannot enable a category disabled by an administrator.
     *
     * @return array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int}
     */
    public function personalPreferences(User $user): array
    {
        $system = $this->systemPreferences();

        return $this->normalizePersonalPreferences($user, $system);
    }

    /**
     * @param  array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int}  $system
     * @return array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int}
     */
    private function normalizePersonalPreferences(User $user, array $system): array
    {
        $stored = $user->notification_preferences ?? [];
        $storedLeadHours = is_int($stored['lead_hours'] ?? null)
            ? $stored['lead_hours']
            : $system['lead_hours'];

        return [
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'overdue_tasks' => (bool) ($stored['overdue_tasks'] ?? true),
            'upcoming_tasks' => (bool) ($stored['upcoming_tasks'] ?? true),
            'meetings' => (bool) ($stored['meetings'] ?? true),
            'lead_hours' => max(1, min($system['lead_hours'], $storedLeadHours)),
        ];
    }

    /**
     * Resolve a destination from current records and permissions, never from
     * a stored URL. A null result means the notification has become stale.
     */
    public function destination(User $user, DatabaseNotification $notification): ?string
    {
        if ($notification->type !== self::TYPE || ! $this->validData($notification->data)) {
            return null;
        }

        $preferences = $this->effectivePreferences($user, $this->systemPreferences());
        if (! $preferences['enabled']) {
            return null;
        }

        /** @var NotificationData $data */
        $data = $notification->data;

        if ($data['source_type'] === 'task') {
            $task = Task::query()
                ->with(['project', 'status'])
                ->find($data['source_id']);

            if (! $task instanceof Task
                || $task->archived_at !== null
                || $task->project->archived_at !== null
                || in_array($task->status->semantic, ['done', 'cancelled'], true)
                || ! $user->can('view', $task)) {
                return null;
            }

            $overdue = $task->due_at->lessThan(Date::now());
            if (($overdue && ! $preferences['overdue_tasks'])
                || (! $overdue && (! $preferences['upcoming_tasks']
                    || ! $task->due_at->betweenIncluded(
                        Date::now(),
                        Date::now()->addHours($preferences['lead_hours']),
                    )))
                || $data['tone'] !== ($overdue ? 'danger' : 'warning')
                || $data['scheduled_at'] !== $task->due_at->toIso8601String()) {
                return null;
            }

            return $user->can('update', $task)
                ? route('tasks.edit', $task, false)
                : route('tasks.index', [
                    'project' => $task->project_id,
                    'q' => $task->code,
                ], false);
        }

        $meeting = Meeting::query()
            ->with('timelineEntry.project')
            ->find($data['source_id']);
        if (! $meeting instanceof Meeting
            || $meeting->archived_at !== null
            || $meeting->timelineEntry->archived_at !== null
            || $meeting->timelineEntry->project->archived_at !== null
            || in_array($meeting->timelineEntry->status, ['completed', 'cancelled'], true)
            || ! $user->can('view', $meeting->timelineEntry->project)
            || ! $preferences['meetings']
            || ! $meeting->timelineEntry->starts_at->betweenIncluded(
                Date::now(),
                Date::now()->addHours($preferences['lead_hours']),
            )
            || $data['scheduled_at'] !== $meeting->timelineEntry->starts_at->toIso8601String()) {
            return null;
        }

        return route('projects.show', [
            'project' => $meeting->timelineEntry->project,
            'tab' => 'meetings',
        ], false);
    }

    /**
     * @param  array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int}  $preferences
     * @param  array{created: int, updated: int, deleted: int, users: int}  $summary
     */
    private function syncUser(User $user, array $preferences, array &$summary): void
    {
        $desired = $preferences['enabled']
            ? $this->candidates($user, $preferences)
            : [];
        $desiredIds = array_keys($desired);

        DB::transaction(function () use ($user, $desired, $desiredIds, &$summary): void {
            foreach ($desired as $id => $data) {
                $notification = DatabaseNotification::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                if (! $notification instanceof DatabaseNotification) {
                    DatabaseNotification::query()->create([
                        'id' => $id,
                        'type' => self::TYPE,
                        'notifiable_type' => User::class,
                        'notifiable_id' => $user->id,
                        'data' => $data,
                        'read_at' => null,
                    ]);
                    $summary['created']++;

                    continue;
                }

                if ($notification->type !== self::TYPE
                    || $notification->notifiable_type !== User::class
                    || (int) $notification->notifiable_id !== $user->id) {
                    continue;
                }

                /** @var array<string, mixed> $currentData */
                $currentData = $notification->data;
                if ($currentData === $data) {
                    continue;
                }

                $notification->forceFill([
                    'data' => $data,
                    'read_at' => ($currentData['fingerprint'] ?? null) === $data['fingerprint']
                        ? $notification->read_at
                        : null,
                ])->save();
                $summary['updated']++;
            }

            $stale = DatabaseNotification::query()
                ->where('type', self::TYPE)
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id);

            if ($desiredIds !== []) {
                $stale->whereNotIn('id', $desiredIds);
            }

            $summary['deleted'] += $stale->delete();
        }, 5);
    }

    /**
     * @param  array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int}  $preferences
     * @return array<string, NotificationData>
     */
    private function candidates(User $user, array $preferences): array
    {
        $now = Date::now();
        $cutoff = $now->copy()->addHours($preferences['lead_hours']);
        $items = [];

        if ($preferences['overdue_tasks'] || $preferences['upcoming_tasks']) {
            $tasks = $this->openTasks($user)
                ->with('project:id,code,name,manager_id,archived_at')
                ->where(function (Builder $due) use ($now, $cutoff, $preferences): void {
                    if ($preferences['overdue_tasks'] && $preferences['upcoming_tasks']) {
                        $due->where('due_at', '<=', $cutoff);
                    } elseif ($preferences['overdue_tasks']) {
                        $due->where('due_at', '<', $now);
                    } else {
                        $due->whereBetween('due_at', [$now, $cutoff]);
                    }
                })
                ->orderBy('id')
                ->get();

            foreach ($tasks as $task) {
                $overdue = $task->due_at->lessThan($now);
                $data = [
                    'source_type' => 'task',
                    'source_id' => $task->id,
                    'tone' => $overdue ? 'danger' : 'warning',
                    'label' => $overdue ? 'مهمة متأخرة' : 'موعد مهمة قريب',
                    'title' => $task->title,
                    'project' => $task->project->name,
                    'project_code' => $task->project->code,
                    'scheduled_at' => $task->due_at->toIso8601String(),
                ];
                $items[$this->stableId($user, 'task', $task->id)] = $this->withFingerprint($data);
            }
        }

        if ($preferences['meetings']) {
            $meetings = Meeting::query()
                ->whereNull('meetings.archived_at')
                ->whereHas('timelineEntry', function (Builder $timeline) use ($user, $now, $cutoff): void {
                    $timeline
                        ->whereNull('timeline_entries.archived_at')
                        ->whereIn('project_id', $this->visibleProjectIds($user))
                        ->whereBetween('starts_at', [$now, $cutoff])
                        ->whereNotIn('status', ['completed', 'cancelled']);
                })
                ->with('timelineEntry.project:id,code,name,archived_at')
                ->orderBy('id')
                ->get();

            foreach ($meetings as $meeting) {
                $timeline = $meeting->timelineEntry;
                $data = [
                    'source_type' => 'meeting',
                    'source_id' => $meeting->id,
                    'tone' => 'info',
                    'label' => 'اجتماع قريب',
                    'title' => $timeline->title,
                    'project' => $timeline->project->name,
                    'project_code' => $timeline->project->code,
                    'scheduled_at' => $timeline->starts_at->toIso8601String(),
                ];
                $items[$this->stableId($user, 'meeting', $meeting->id)] = $this->withFingerprint($data);
            }
        }

        return $items;
    }

    /** @return Builder<Task> */
    private function openTasks(User $user): Builder
    {
        return Task::query()
            ->whereIn('project_id', $this->visibleProjectIds($user))
            ->whereNull('archived_at')
            ->whereNotNull('due_at')
            ->whereHas('status', fn (Builder $status) => $status
                ->whereNotIn('semantic', ['done', 'cancelled']));
    }

    /** @return Builder<Project> */
    private function visibleProjectIds(User $user): Builder
    {
        return Project::query()
            ->select('projects.id')
            ->visibleTo($user)
            ->whereNull('projects.archived_at');
    }

    /**
     * @param  array{source_type: 'task'|'meeting', source_id: int, tone: 'danger'|'warning'|'info', label: string, title: string, project: string, project_code: string, scheduled_at: string}  $data
     * @return NotificationData
     */
    private function withFingerprint(array $data): array
    {
        return [
            ...$data,
            'fingerprint' => hash('sha256', json_encode([
                $data['source_type'],
                $data['source_id'],
                $data['tone'],
                $data['scheduled_at'],
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Generate a deterministic RFC 4122-shaped ID for one user/source pair.
     */
    private function stableId(User $user, string $sourceType, int $sourceId): string
    {
        $hex = hash('sha256', "project-desk-notification|{$user->id}|{$sourceType}|{$sourceId}");
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /** @return array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int} */
    private function systemPreferences(): array
    {
        $preferences = $this->settings->group('notifications');

        return [
            'enabled' => (bool) ($preferences['enabled'] ?? true),
            'overdue_tasks' => (bool) ($preferences['overdue_tasks'] ?? true),
            'upcoming_tasks' => (bool) ($preferences['upcoming_tasks'] ?? true),
            'meetings' => (bool) ($preferences['meetings'] ?? true),
            'lead_hours' => max(1, min(168, (int) ($preferences['lead_hours'] ?? 24))),
        ];
    }

    /**
     * @param  array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int}  $system
     * @return array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int}
     */
    private function effectivePreferences(User $user, array $system): array
    {
        $personal = $this->normalizePersonalPreferences($user, $system);

        return [
            'enabled' => $system['enabled'] && $personal['enabled'],
            'overdue_tasks' => $system['overdue_tasks'] && $personal['overdue_tasks'],
            'upcoming_tasks' => $system['upcoming_tasks'] && $personal['upcoming_tasks'],
            'meetings' => $system['meetings'] && $personal['meetings'],
            'lead_hours' => min($system['lead_hours'], $personal['lead_hours']),
        ];
    }

    /** @param array<string, mixed> $data */
    private function validData(array $data): bool
    {
        return in_array($data['source_type'] ?? null, ['task', 'meeting'], true)
            && is_int($data['source_id'] ?? null)
            && in_array($data['tone'] ?? null, ['danger', 'warning', 'info'], true)
            && is_string($data['label'] ?? null)
            && is_string($data['title'] ?? null)
            && is_string($data['project'] ?? null)
            && is_string($data['project_code'] ?? null)
            && is_string($data['scheduled_at'] ?? null)
            && is_string($data['fingerprint'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{enabled: bool, overdue_tasks: bool, upcoming_tasks: bool, meetings: bool, lead_hours: int}  $preferences
     */
    private function matchesPreferences(array $data, array $preferences): bool
    {
        if (! $this->validData($data)) {
            return false;
        }

        if ($data['source_type'] === 'task' && $data['tone'] === 'danger') {
            return $preferences['overdue_tasks'];
        }

        if ($data['source_type'] === 'task' && ! $preferences['upcoming_tasks']) {
            return false;
        }

        if ($data['source_type'] === 'meeting' && ! $preferences['meetings']) {
            return false;
        }

        $scheduledAt = Date::parse($data['scheduled_at']);

        return $scheduledAt->betweenIncluded(
            Date::now(),
            Date::now()->addHours($preferences['lead_hours']),
        );
    }

    /** @return array{enabled: bool, count: int, lead_hours: int, items: array{}} */
    private function emptyFeed(bool $enabled, int $leadHours): array
    {
        return [
            'enabled' => $enabled,
            'count' => 0,
            'lead_hours' => $leadHours,
            'items' => [],
        ];
    }

    /** @param 'danger'|'warning'|'info' $tone */
    private function toneOrder(string $tone): int
    {
        return match ($tone) {
            'danger' => 0,
            'warning' => 1,
            'info' => 2,
        };
    }
}
