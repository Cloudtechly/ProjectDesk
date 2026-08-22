<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Models\TimelineEntry;
use App\Models\User;
use App\Models\WorkflowStatus;
use App\Services\NotificationCenterService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-12 08:00:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_scheduler_persists_one_notification_per_visible_user_and_source_without_duplicates(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('viewer');
        $unrelatedManager = $this->makeUser('project_manager');
        $projectStatus = $this->makeStatus('project', 'active', 'in_progress');
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $visible = $this->makeProject($manager, $projectStatus);
        $hidden = $this->makeProject($unrelatedManager, $projectStatus);
        $visible->members()->attach($member, [
            'project_role' => 'member',
            'status' => 'active',
        ]);

        $task = $this->makeTask($visible, $openStatus, 'تسليم النسخة التجريبية', now()->addHours(4));
        $this->makeTask($hidden, $openStatus, 'مهمة سرية', now()->addHours(2));

        $this->syncNotifications();
        $this->syncNotifications();

        $managerNotification = $this->notificationFor($manager);
        $memberNotification = $this->notificationFor($member);

        $this->assertSame('task', $managerNotification->data['source_type']);
        $this->assertSame($task->id, $managerNotification->data['source_id']);
        $this->assertSame($task->id, $memberNotification->data['source_id']);
        $this->assertDatabaseCount('notifications', 3);
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertSame(1, $member->notifications()->count());
        $this->assertSame(1, $unrelatedManager->notifications()->count());

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.enabled', true)
                ->where('notifications.count', 1)
                ->where('notifications.lead_hours', 24)
                ->has('notifications.items', 1)
                ->where('notifications.items.0.id', $managerNotification->id)
                ->where('notifications.items.0.type', 'task')
                ->where('notifications.items.0.title', $task->title)
                ->where('notifications.items.0.open_url', route(
                    'notifications.open',
                    $managerNotification,
                    false,
                ))
                ->missing('notifications.items.0.href'));
    }

    public function test_sync_updates_the_same_record_and_reopens_it_when_the_deadline_changes(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $task = $this->makeTask($project, $openStatus, 'مراجعة المتطلبات', now()->addHours(3));

        $this->syncNotifications();
        $notification = $this->notificationFor($manager);
        $notification->markAsRead();
        $firstId = $notification->id;

        $task->update(['due_at' => now()->subHour()]);
        $this->syncNotifications();

        $updated = $this->notificationFor($manager);
        $this->assertSame($firstId, $updated->id);
        $this->assertNull($updated->read_at);
        $this->assertSame('danger', $updated->data['tone']);
        $this->assertSame(1, $manager->notifications()->count());
    }

    public function test_opening_a_notification_marks_it_read_and_redirects_to_a_current_authorized_target(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $task = $this->makeTask($project, $openStatus, 'اعتماد كراسة المتطلبات', now()->addHour());

        $this->syncNotifications();
        $notification = $this->notificationFor($manager);

        $this->actingAs($manager)
            ->post(route('notifications.open', $notification))
            ->assertRedirect(route('tasks.edit', $task, false));

        $this->assertNotNull($notification->fresh()->read_at);

        $this->syncNotifications();
        $this->assertNotNull($notification->fresh()->read_at);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.count', 0)
                ->has('notifications.items', 0));
    }

    public function test_read_only_member_opens_a_notification_through_a_viewable_destination(): void
    {
        $manager = $this->makeUser('project_manager');
        $viewer = $this->makeUser('viewer');
        $project = $this->makeProject($manager);
        $project->members()->attach($viewer, [
            'project_role' => 'member',
            'status' => 'active',
        ]);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $task = $this->makeTask($project, $openStatus, 'مهمة مرئية للقراءة', now()->addHour());

        $this->syncNotifications();
        $notification = $this->notificationFor($viewer);
        $destination = route('tasks.index', [
            'project' => $project->id,
            'q' => $task->code,
        ], false);

        $this->actingAs($viewer)
            ->post(route('notifications.open', $notification))
            ->assertRedirect($destination);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->get($destination)->assertOk();
    }

    public function test_user_cannot_open_or_infer_another_users_notification(): void
    {
        $manager = $this->makeUser('project_manager');
        $attacker = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $this->makeTask($project, $openStatus, 'بيانات مشروع خاصة', now()->addHour());

        $this->syncNotifications();
        $notification = $this->notificationFor($manager);

        $this->actingAs($attacker)
            ->post(route('notifications.open', $notification))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_open_endpoint_deletes_a_notification_that_became_stale_before_the_next_sync(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $doneStatus = $this->makeStatus('task', 'done', 'done');
        $task = $this->makeTask($project, $openStatus, 'مهمة أُنجزت للتو', now()->addHour());

        $this->syncNotifications();
        $notification = $this->notificationFor($manager);
        $task->update(['status_id' => $doneStatus->id]);

        $this->actingAs($manager)
            ->post(route('notifications.open', $notification))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_cancelled_or_completed_sources_are_removed_on_the_next_sync(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $doneStatus = $this->makeStatus('task', 'done', 'done');
        $task = $this->makeTask($project, $openStatus, 'مهمة قابلة للإلغاء', now()->addHours(3));
        $meeting = $this->makeMeeting($project, 'اجتماع مراجعة العميل', now()->addHours(2));

        $this->syncNotifications();
        $this->assertSame(2, $manager->notifications()->count());

        $task->update(['status_id' => $doneStatus->id]);
        $meeting->timelineEntry->update(['archived_at' => now()]);
        $this->syncNotifications();

        $this->assertSame(0, $manager->notifications()->count());
    }

    public function test_sync_removes_notifications_after_project_access_is_revoked(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('viewer');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, [
            'project_role' => 'member',
            'status' => 'active',
        ]);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $this->makeTask($project, $openStatus, 'مهمة العضو', now()->addHour());

        $this->syncNotifications();
        $this->assertSame(1, $member->notifications()->count());

        $project->members()->updateExistingPivot($member->id, ['status' => 'inactive']);
        $this->syncNotifications();

        $this->assertSame(0, $member->notifications()->count());
        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.count', 0)
                ->has('notifications.items', 0));
    }

    public function test_notification_preferences_control_generation_and_disable_clears_existing_records(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $this->makeTask($project, $openStatus, 'داخل المهلة', now()->addHours(5));
        $this->makeTask($project, $openStatus, 'خارج المهلة', now()->addHours(7));
        $this->makeTask($project, $openStatus, 'مهمة متأخرة', now()->subHour());
        $this->makeMeeting($project, 'اجتماع معطل', now()->addHours(2));

        $this->setNotificationSettings([
            'enabled' => true,
            'overdue_tasks' => false,
            'upcoming_tasks' => true,
            'meetings' => false,
            'lead_hours' => 6,
        ]);

        $this->syncNotifications();
        $this->assertSame(1, $manager->notifications()->count());

        $this->setNotificationSettings(['enabled' => false]);
        $this->syncNotifications();

        $this->assertDatabaseMissing('notifications', [
            'type' => NotificationCenterService::TYPE,
        ]);
        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.enabled', false)
                ->where('notifications.count', 0));
    }

    public function test_personal_preferences_narrow_system_categories_and_lead_window(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);
        $openStatus = $this->makeStatus('task', 'open', 'open');
        $insideWindow = $this->makeTask($project, $openStatus, 'ضمن المهلة الشخصية', now()->addHours(2));
        $this->makeTask($project, $openStatus, 'خارج المهلة الشخصية', now()->addHours(4));
        $this->makeTask($project, $openStatus, 'متأخرة ومعطلة شخصياً', now()->subHour());
        $this->makeMeeting($project, 'اجتماع معطل شخصياً', now()->addHour());
        $manager->update([
            'notification_preferences' => [
                'enabled' => true,
                'overdue_tasks' => false,
                'upcoming_tasks' => true,
                'meetings' => false,
                'lead_hours' => 3,
            ],
        ]);

        $this->syncNotifications();

        $notification = $this->notificationFor($manager);
        $this->assertSame($insideWindow->id, $notification->data['source_id']);
        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.enabled', true)
                ->where('notifications.lead_hours', 3)
                ->where('notifications.count', 1));
    }

    private function makeTask(
        Project $project,
        WorkflowStatus $status,
        string $title,
        CarbonInterface $dueAt,
    ): Task {
        return Task::query()->create([
            'project_id' => $project->id,
            'code' => 'TASK-'.fake()->unique()->numerify('#####'),
            'title' => $title,
            'status_id' => $status->id,
            'priority' => 'medium',
            'start_at' => now()->subDay(),
            'due_at' => $dueAt,
        ]);
    }

    private function makeMeeting(Project $project, string $title, CarbonInterface $startsAt): Meeting
    {
        $timeline = TimelineEntry::query()->create([
            'project_id' => $project->id,
            'kind' => 'meeting',
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'status' => 'planned',
            'owner_id' => $project->manager_id,
        ]);

        return Meeting::query()->create([
            'timeline_entry_id' => $timeline->id,
            'organizer_id' => $project->manager_id,
        ]);
    }

    private function notificationFor(User $user): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()
            ->where('type', NotificationCenterService::TYPE)
            ->sole();

        return $notification;
    }

    private function syncNotifications(): void
    {
        $this->assertSame(0, Artisan::call('project-desk:sync-notifications'));
    }

    /** @param array<string, mixed> $values */
    private function setNotificationSettings(array $values): void
    {
        foreach ($values as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['group' => 'notifications', 'key' => $key],
                ['value' => $value, 'is_secret' => false],
            );
        }
    }
}
