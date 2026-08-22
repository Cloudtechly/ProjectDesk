<?php

namespace Tests\Feature;

use App\Http\Controllers\SystemSettingsController;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->prefix('/_tests/system-settings')->group(function (): void {
            Route::get('/', [SystemSettingsController::class, 'index']);
            Route::put('/{group}', [SystemSettingsController::class, 'update']);
            Route::delete('/{group}', [SystemSettingsController::class, 'destroy']);
        });
    }

    public function test_admin_reads_all_groups_merged_with_defaults_without_persisting_them(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->getJson('/_tests/system-settings')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'general' => [
                        'company_name' => null,
                        'timezone' => 'Africa/Tripoli',
                    ],
                    'company' => [
                        'display_name' => null,
                        'legal_name' => null,
                        'email' => null,
                        'phone' => null,
                        'address' => null,
                        'website' => 'https://cloudtech.ly',
                        'tax_number' => null,
                        'registration_number' => null,
                        'logo_asset' => '/brand/cloudtech-logo.svg',
                        'invoice_prefix' => 'CT-INV',
                        'number_padding' => 3,
                    ],
                    'notifications' => [
                        'enabled' => true,
                        'overdue_tasks' => true,
                        'upcoming_tasks' => true,
                        'meetings' => true,
                        'lead_hours' => 24,
                    ],
                    'automatic_backup' => [
                        'enabled' => false,
                        'frequency' => 'daily',
                        'time' => '02:00',
                        'retention_count' => 30,
                    ],
                    'calendar' => [
                        'week_start' => 0,
                        'weekend_days' => [5, 6],
                    ],
                ],
            ]);

        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_partial_updates_upsert_only_submitted_values_and_preserve_other_values(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->putJson('/_tests/system-settings/notifications', [
                'enabled' => false,
                'lead_hours' => 72,
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.lead_hours', 72)
            ->assertJsonPath('data.meetings', true);

        $this->putJson('/_tests/system-settings/notifications', ['meetings' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.lead_hours', 72)
            ->assertJsonPath('data.meetings', false)
            ->assertJsonPath('data.overdue_tasks', true);

        $this->assertDatabaseCount('system_settings', 3);
        $this->assertSame(3, DB::table('activity_logs')->where('subject_type', SystemSetting::class)->count());
    }

    public function test_general_settings_accept_nullable_company_and_a_valid_timezone(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->putJson('/_tests/system-settings/general', [
                'company_name' => null,
                'timezone' => 'Europe/London',
            ])
            ->assertOk()
            ->assertJsonPath('data.company_name', null)
            ->assertJsonPath('data.timezone', 'Europe/London');

        $company = SystemSetting::query()->where('group', 'general')->where('key', 'company_name')->firstOrFail();
        $this->assertNull($company->value);
        $this->assertSame('null', $company->getRawOriginal('value'));
    }

    public function test_each_group_enforces_its_validation_contract(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $this->putJson('/_tests/system-settings/general', [
            'company_name' => str_repeat('x', 256),
            'timezone' => 'Mars/Olympus',
        ])->assertUnprocessable()->assertJsonValidationErrors(['company_name', 'timezone']);

        $this->putJson('/_tests/system-settings/company', [
            'email' => 'not-an-email',
            'website' => 'javascript:alert(1)',
            'invoice_prefix' => '../INV',
            'number_padding' => 9,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'email', 'website', 'invoice_prefix', 'number_padding',
        ]);

        $this->putJson('/_tests/system-settings/notifications', [
            'enabled' => 'sometimes',
            'lead_hours' => 169,
        ])->assertUnprocessable()->assertJsonValidationErrors(['enabled', 'lead_hours']);

        $this->putJson('/_tests/system-settings/automatic_backup', [
            'frequency' => 'monthly',
            'time' => '25:90',
            'retention_count' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['frequency', 'time', 'retention_count']);

        $this->putJson('/_tests/system-settings/calendar', [
            'week_start' => 7,
            'weekend_days' => [5, 5],
        ])->assertUnprocessable()->assertJsonValidationErrors(['week_start', 'weekend_days.1']);

        $this->putJson('/_tests/system-settings/calendar', [
            'weekend_days' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['weekend_days']);

        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_reset_deletes_only_group_overrides_returns_defaults_and_is_audited(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $this->putJson('/_tests/system-settings/notifications', [
            'enabled' => false,
            'lead_hours' => 96,
        ])->assertOk();
        $this->putJson('/_tests/system-settings/general', [
            'company_name' => 'CloudTech Libya',
        ])->assertOk();

        $this->deleteJson('/_tests/system-settings/notifications')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'enabled' => true,
                    'overdue_tasks' => true,
                    'upcoming_tasks' => true,
                    'meetings' => true,
                    'lead_hours' => 24,
                ],
            ]);

        $this->assertDatabaseMissing('system_settings', ['group' => 'notifications']);
        $this->assertDatabaseHas('system_settings', ['group' => 'general', 'key' => 'company_name']);
        $this->assertSame(2, DB::table('activity_logs')->where('action', 'system_setting.reset')->count());
    }

    public function test_legacy_commercial_prefix_settings_are_hidden_and_preserved(): void
    {
        $admin = $this->makeUser('admin');
        SystemSetting::query()->create([
            'group' => 'company',
            'key' => 'proposal_prefix',
            'value' => 'LEGACY-PROP',
            'is_secret' => false,
        ]);

        $this->actingAs($admin)
            ->getJson('/_tests/system-settings')
            ->assertOk()
            ->assertJsonMissingPath('data.company.proposal_prefix');

        $this->deleteJson('/_tests/system-settings/company')->assertOk();
        $this->assertDatabaseHas('system_settings', [
            'group' => 'company',
            'key' => 'proposal_prefix',
            'value' => '"LEGACY-PROP"',
        ]);
    }

    public function test_only_active_non_archived_admins_can_read_update_or_reset_settings(): void
    {
        $manager = $this->makeUser('project_manager');
        $inactiveAdmin = $this->makeUser('admin', 'inactive');
        $archivedAdmin = User::factory()->create([
            'global_role' => 'admin',
            'status' => 'active',
            'archived_at' => now(),
        ]);

        foreach ([$manager, $inactiveAdmin, $archivedAdmin] as $user) {
            $this->actingAs($user)->getJson('/_tests/system-settings')->assertForbidden();
            $this->actingAs($user)->putJson('/_tests/system-settings/general', ['company_name' => 'Denied'])->assertForbidden();
            $this->actingAs($user)->deleteJson('/_tests/system-settings/general')->assertForbidden();
        }

        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_unknown_groups_return_not_found_and_empty_partial_update_is_idempotent(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)
            ->putJson('/_tests/system-settings/unknown', [])
            ->assertNotFound();
        $this->deleteJson('/_tests/system-settings/unknown')->assertNotFound();

        $this->putJson('/_tests/system-settings/calendar', [])
            ->assertOk()
            ->assertJsonPath('data.week_start', 0)
            ->assertJsonPath('data.weekend_days', [5, 6]);

        $this->assertDatabaseCount('system_settings', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
