<?php

namespace Tests\Feature\Settings;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_view_and_update_personal_notification_preferences(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'archived_at' => null,
            'notification_preferences' => null,
        ]);

        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/notifications')
                ->where('preferences.enabled', true)
                ->where('preferences.overdue_tasks', true)
                ->where('preferences.upcoming_tasks', true)
                ->where('preferences.meetings', true)
                ->where('preferences.lead_hours', 24)
                ->where('systemPolicy.lead_hours', 24));

        $this->patch(route('notification-preferences.update'), [
            'enabled' => true,
            'overdue_tasks' => false,
            'upcoming_tasks' => true,
            'meetings' => false,
            'lead_hours' => 8,
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('notification-preferences.edit'));

        $this->assertSame([
            'enabled' => true,
            'overdue_tasks' => false,
            'upcoming_tasks' => true,
            'meetings' => false,
            'lead_hours' => 8,
        ], $user->fresh()->notification_preferences);
        $this->assertDatabaseHas('activity_logs', [
            'actor_id' => $user->id,
            'action' => 'notification_preferences.updated',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
    }

    public function test_personal_lead_window_cannot_exceed_the_current_system_policy(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'archived_at' => null,
        ]);
        SystemSetting::query()->create([
            'group' => 'notifications',
            'key' => 'lead_hours',
            'value' => 6,
            'is_secret' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('notification-preferences.update'), [
                'enabled' => true,
                'overdue_tasks' => true,
                'upcoming_tasks' => true,
                'meetings' => true,
                'lead_hours' => 7,
            ])
            ->assertSessionHasErrors('lead_hours');

        $this->assertNull($user->fresh()->notification_preferences);
    }

    public function test_guest_cannot_access_personal_notification_preferences(): void
    {
        $this->get('/settings/notifications')->assertRedirect('/login');
        $this->patch('/settings/notifications', [])->assertRedirect('/login');
    }
}
