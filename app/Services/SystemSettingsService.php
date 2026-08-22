<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SystemSettingsService
{
    /**
     * Zero is Sunday, matching the weekly schedule used by Project Desk.
     * Friday and Saturday are the default Libyan weekend.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEFAULTS = [
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
    ];

    public function __construct(private readonly ActivityLogger $activityLogger) {}

    public static function supportsGroup(string $group): bool
    {
        return array_key_exists($group, self::DEFAULTS);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $settings = self::DEFAULTS;

        SystemSetting::query()
            ->whereIn('group', array_keys(self::DEFAULTS))
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->each(function (SystemSetting $setting) use (&$settings): void {
                if (array_key_exists($setting->key, $settings[$setting->group])) {
                    $settings[$setting->group][$setting->key] = $setting->value;
                }
            });

        return $settings;
    }

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        $this->assertSupportedGroup($group);

        return $this->all()[$group];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(
        string $group,
        array $values,
        User $actor,
        ?Request $request = null,
    ): array {
        $this->assertSupportedGroup($group);
        $unknownKeys = array_diff(array_keys($values), array_keys(self::DEFAULTS[$group]));
        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('Unsupported setting keys: '.implode(', ', $unknownKeys));
        }

        DB::transaction(function () use ($group, $values, $actor, $request): void {
            foreach ($values as $key => $value) {
                $setting = SystemSetting::query()
                    ->where('group', $group)
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();
                $before = $setting === null ? [] : $this->snapshot($setting);

                if ($setting === null) {
                    $setting = SystemSetting::query()->create([
                        'group' => $group,
                        'key' => $key,
                        'value' => $value,
                        'is_secret' => false,
                    ]);
                    $action = 'system_setting.created';
                } else {
                    if ($setting->value === $value) {
                        continue;
                    }

                    $setting->value = $value;
                    $setting->save();
                    $action = 'system_setting.updated';
                }

                $this->activityLogger->record(
                    $setting,
                    $action,
                    $actor,
                    $before,
                    $this->snapshot($setting),
                    $request,
                );
            }
        }, 5);

        return $this->group($group);
    }

    /** @return array<string, mixed> */
    public function reset(
        string $group,
        User $actor,
        ?Request $request = null,
    ): array {
        $this->assertSupportedGroup($group);

        DB::transaction(function () use ($group, $actor, $request): void {
            $settings = SystemSetting::query()
                ->where('group', $group)
                ->whereIn('key', array_keys(self::DEFAULTS[$group]))
                ->lockForUpdate()
                ->get();

            foreach ($settings as $setting) {
                $before = $this->snapshot($setting);
                $default = self::DEFAULTS[$group][$setting->key] ?? null;
                $setting->delete();
                $this->activityLogger->record(
                    $setting,
                    'system_setting.reset',
                    $actor,
                    $before,
                    [
                        'group' => $group,
                        'key' => $setting->key,
                        'value' => $default,
                        'source' => 'default',
                    ],
                    $request,
                );
            }
        }, 5);

        return self::DEFAULTS[$group];
    }

    private function assertSupportedGroup(string $group): void
    {
        if (! self::supportsGroup($group)) {
            throw new InvalidArgumentException("Unsupported settings group [{$group}].");
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(SystemSetting $setting): array
    {
        return [
            'group' => $setting->group,
            'key' => $setting->key,
            'value' => $setting->value,
            'is_secret' => $setting->is_secret,
        ];
    }
}
