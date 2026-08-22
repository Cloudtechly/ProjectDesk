<?php

namespace Tests\Feature;

use App\Services\RestoreWriteFence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class RestoreWriteFenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_exclusive_restore_enables_maintenance_and_always_releases_it(): void
    {
        $fence = $this->app->make(RestoreWriteFence::class);

        $result = $fence->exclusive(function (): string {
            $this->assertTrue($this->app->isDownForMaintenance());

            return 'restored';
        });

        $this->assertSame('restored', $result);
        $this->assertFalse($this->app->isDownForMaintenance());

        try {
            $fence->exclusive(function (): never {
                $this->assertTrue($this->app->isDownForMaintenance());
                throw new RuntimeException('simulated restore failure');
            });
            $this->fail('The simulated restore exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated restore failure', $exception->getMessage());
        }

        $this->assertFalse($this->app->isDownForMaintenance());
    }

    public function test_built_in_scheduled_writers_skip_while_restore_lock_is_exclusive(): void
    {
        $fence = $this->app->make(RestoreWriteFence::class);

        $fence->exclusive(function (): void {
            $this->assertSame(0, Artisan::call('project-desk:sync-notifications'));
            $this->assertStringContainsString('الاستعادة قيد التنفيذ', Artisan::output());

            $this->assertSame(0, Artisan::call('project-desk:automatic-backup'));
            $this->assertStringContainsString('الاستعادة قيد التنفيذ', Artisan::output());
        });

        $this->assertFalse($this->app->isDownForMaintenance());
    }
}
