<?php

namespace Tests\Feature;

use App\Models\DataJob;
use App\Models\FileObject;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SqliteBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class AutomaticBackupCommandTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_disabled_automatic_backup_is_a_successful_no_op(): void
    {
        $backups = Mockery::mock(SqliteBackupService::class);
        $backups->shouldNotReceive('create');
        $this->app->instance(SqliteBackupService::class, $backups);

        $this->artisan('project-desk:automatic-backup')
            ->expectsOutputToContain('النسخ التلقائي معطّل')
            ->assertSuccessful();

        $this->assertDatabaseCount('data_jobs', 0);
    }

    public function test_daily_backup_does_not_run_before_its_local_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 01:59:00', 'Africa/Tripoli'));
        $this->configureAutomaticBackup(frequency: 'daily', time: '02:00');
        $backups = Mockery::mock(SqliteBackupService::class);
        $backups->shouldNotReceive('create');
        $this->app->instance(SqliteBackupService::class, $backups);

        $this->artisan('project-desk:automatic-backup')
            ->expectsOutputToContain('لم يحن موعد')
            ->assertSuccessful();
    }

    public function test_due_daily_backup_uses_the_first_active_admin_and_records_period_context(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 02:05:00', 'Africa/Tripoli'));
        $this->configureAutomaticBackup(frequency: 'daily', time: '02:00', retentionCount: 12);
        $this->makeUser('admin', 'inactive');
        $activeAdmin = $this->makeUser('admin');
        $laterAdmin = $this->makeUser('admin');
        $backups = Mockery::mock(SqliteBackupService::class);
        $backups->shouldReceive('create')
            ->once()
            ->withArgs(function (User $admin, string $purpose, array $context) use ($activeAdmin, $laterAdmin): bool {
                return $admin->is($activeAdmin)
                    && ! $admin->is($laterAdmin)
                    && $purpose === 'automatic'
                    && $context === [
                        'automatic_period_key' => 'automatic:daily:2026-08-12',
                        'frequency' => 'daily',
                        'scheduled_for' => '2026-08-12T02:00:00+02:00',
                        'timezone' => 'Africa/Tripoli',
                        'week_start' => 0,
                        'retention_count' => 12,
                        'forced' => false,
                    ];
            })
            ->andReturnUsing(fn (User $admin, string $purpose, array $context): DataJob => $this->job($admin, $purpose, $context));
        $backups->shouldReceive('pruneAutomaticBackups')->once()->with(12)->andReturn(0);
        $this->app->instance(SqliteBackupService::class, $backups);

        $this->artisan('project-desk:automatic-backup')
            ->expectsOutputToContain('اكتملت النسخة التلقائية بنجاح')
            ->assertSuccessful();

        $this->assertDatabaseHas('data_jobs', [
            'type' => 'backup',
            'status' => 'succeeded',
            'created_by' => $activeAdmin->id,
        ]);
    }

    public function test_weekly_backup_is_due_after_the_configured_week_start_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', 'Africa/Tripoli'));
        $this->configureAutomaticBackup(frequency: 'weekly', time: '06:30', weekStart: 0);
        $admin = $this->makeUser('admin');
        $backups = Mockery::mock(SqliteBackupService::class);
        $backups->shouldReceive('create')
            ->once()
            ->withArgs(fn (User $selected, string $purpose, array $context): bool => $selected->is($admin)
                && $purpose === 'automatic'
                && $context['automatic_period_key'] === 'automatic:weekly:2026-08-09'
                && $context['scheduled_for'] === '2026-08-09T06:30:00+02:00')
            ->andReturnUsing(fn (User $selected, string $purpose, array $context): DataJob => $this->job($selected, $purpose, $context));
        $backups->shouldReceive('pruneAutomaticBackups')->once()->with(30)->andReturn(0);
        $this->app->instance(SqliteBackupService::class, $backups);

        $this->artisan('project-desk:automatic-backup')->assertSuccessful();
    }

    public function test_successful_backup_in_the_same_period_is_not_duplicated(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 07:00:00', 'Africa/Tripoli'));
        $this->configureAutomaticBackup(frequency: 'daily', time: '02:00');
        $admin = $this->makeUser('admin');
        $this->job($admin, 'automatic', [
            'automatic_period_key' => 'automatic:daily:2026-08-12',
        ]);
        $backups = Mockery::mock(SqliteBackupService::class);
        $backups->shouldNotReceive('create');
        $this->app->instance(SqliteBackupService::class, $backups);

        $this->artisan('project-desk:automatic-backup')
            ->expectsOutputToContain('لهذه الفترة مسبقاً')
            ->assertSuccessful();

        $this->assertDatabaseCount('data_jobs', 1);
    }

    public function test_force_runs_even_when_disabled_and_a_period_backup_exists(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 01:00:00', 'Africa/Tripoli'));
        $admin = $this->makeUser('admin');
        $this->job($admin, 'automatic', [
            'automatic_period_key' => 'automatic:daily:2026-08-12',
        ]);
        $backups = Mockery::mock(SqliteBackupService::class);
        $backups->shouldReceive('create')
            ->once()
            ->withArgs(fn (User $selected, string $purpose, array $context): bool => $selected->is($admin)
                && $purpose === 'automatic'
                && $context['forced'] === true
                && $context['automatic_period_key'] === 'automatic:daily:2026-08-12')
            ->andReturnUsing(fn (User $selected, string $purpose, array $context): DataJob => $this->job($selected, $purpose, $context));
        $backups->shouldReceive('pruneAutomaticBackups')->once()->with(30)->andReturn(0);
        $this->app->instance(SqliteBackupService::class, $backups);

        $this->artisan('project-desk:automatic-backup', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('data_jobs', 2);
    }

    public function test_due_backup_fails_safely_when_there_is_no_active_admin(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 03:00:00', 'Africa/Tripoli'));
        $this->configureAutomaticBackup(frequency: 'daily', time: '02:00');
        $this->makeUser('admin', 'inactive');
        $backups = Mockery::mock(SqliteBackupService::class);
        $backups->shouldNotReceive('create');
        $this->app->instance(SqliteBackupService::class, $backups);

        $this->artisan('project-desk:automatic-backup')
            ->expectsOutputToContain('لا يوجد مدير نشط')
            ->assertFailed();
    }

    public function test_command_is_scheduled_every_minute_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'project-desk:automatic-backup'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
    }

    public function test_retention_prunes_only_older_automatic_backup_files_and_keeps_job_history(): void
    {
        Storage::fake('local');
        $admin = $this->makeUser('admin');
        $files = collect([1, 2])->map(function (int $position) use ($admin): FileObject {
            $key = "backups/project-desk/automatic-{$position}.sqlite";
            $contents = "sqlite-backup-{$position}";
            Storage::disk('local')->put($key, $contents);

            return FileObject::query()->create([
                'disk' => 'local',
                'storage_key' => $key,
                'original_name' => basename($key),
                'mime_type' => 'application/vnd.sqlite3',
                'extension' => 'sqlite',
                'size_bytes' => strlen($contents),
                'checksum_sha256' => hash('sha256', $contents),
                'scan_status' => 'safe',
                'uploaded_by' => $admin->id,
                'uploaded_at' => now()->subDays(3 - $position),
            ]);
        });

        foreach ($files as $position => $file) {
            DataJob::query()->create([
                'type' => 'backup',
                'resource_type' => 'database',
                'format' => 'sqlite',
                'status' => 'succeeded',
                'file_object_id' => $file->id,
                'created_by' => $admin->id,
                'summary' => ['purpose' => 'automatic'],
                'started_at' => now()->subDays(2 - $position),
                'completed_at' => now()->subDays(2 - $position),
            ]);
        }

        $service = $this->app->make(SqliteBackupService::class);
        $this->assertSame(1, $service->pruneAutomaticBackups(1));

        $older = $files->first();
        $newer = $files->last();
        $this->assertDatabaseMissing('file_objects', ['id' => $older->id]);
        $this->assertDatabaseHas('file_objects', ['id' => $newer->id]);
        Storage::disk('local')->assertMissing($older->storage_key);
        Storage::disk('local')->assertExists($newer->storage_key);
        $this->assertDatabaseHas('data_jobs', [
            'file_object_id' => null,
            'status' => 'succeeded',
        ]);
    }

    private function configureAutomaticBackup(
        string $frequency,
        string $time,
        int $retentionCount = 30,
        int $weekStart = 0,
    ): void {
        $this->setting('automatic_backup', 'enabled', true);
        $this->setting('automatic_backup', 'frequency', $frequency);
        $this->setting('automatic_backup', 'time', $time);
        $this->setting('automatic_backup', 'retention_count', $retentionCount);
        $this->setting('general', 'timezone', 'Africa/Tripoli');
        $this->setting('calendar', 'week_start', $weekStart);
    }

    private function setting(string $group, string $key, mixed $value): void
    {
        SystemSetting::query()->create([
            'group' => $group,
            'key' => $key,
            'value' => $value,
            'is_secret' => false,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function job(User $admin, string $purpose, array $context): DataJob
    {
        return DataJob::query()->create([
            'type' => 'backup',
            'resource_type' => 'system',
            'format' => 'pdesk',
            'status' => 'succeeded',
            'created_by' => $admin->id,
            'summary' => [...$context, 'purpose' => $purpose],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
