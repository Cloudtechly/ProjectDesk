<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessCommandTest extends TestCase
{
    public function test_local_environment_fails_closed_and_reports_machine_readable_checks(): void
    {
        $exitCode = Artisan::call('project-desk:production-readiness', ['--json' => true]);
        $output = Artisan::output();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('"ready": false', $output);
        self::assertStringContainsString('environment.production', $output);
    }
}
