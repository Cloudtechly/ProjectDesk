<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
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

    public function test_schema_two_accepts_complete_fresh_and_internally_consistent_evidence(): void
    {
        $checks = $this->runWithEvidence($this->validEvidence());

        foreach ([
            'evidence.schema_version',
            'evidence.generated_at',
            'evidence.release_sha_matches',
            'evidence.environment.database_path',
            'evidence.scanner.eicar.passed',
            'evidence.offsite_backup.retention_until',
            'evidence.services.failed_jobs',
            'evidence.restore_drill.backup_checksum_sha256',
            'evidence.accessibility.screen_reader',
            'evidence.performance.inp_samples',
            'evidence.performance.search_samples',
            'evidence.pilot.success_calculation',
            'evidence.pilot.success',
            'evidence.approvals.operations.approved_at',
        ] as $name) {
            self::assertSame('passed', $checks[$name]['status'] ?? null, "Expected {$name} to pass.");
        }
    }

    public function test_schema_two_rejects_stale_forged_and_incomplete_evidence(): void
    {
        $evidence = $this->validEvidence();
        $evidence['schema_version'] = 1;
        $evidence['release_sha'] = str_repeat('c', 40);
        $evidence['scanner']['release_sha'] = str_repeat('c', 40);
        $evidence['environment']['ubuntu_version'] = '22.04 LTS';
        $evidence['environment']['php_version'] = '8.3.1';
        $evidence['environment']['database_path'] = '/tmp/forged.sqlite';
        $evidence['restore_drill']['completed_at'] = CarbonImmutable::now()->subDays(36)->toIso8601String();
        $evidence['restore_drill']['backup_checksum_sha256'] = 'not-a-checksum';
        $evidence['accessibility']['keyboard'] = false;
        $evidence['performance']['inp_sample_count'] = 199;
        $evidence['performance']['search_sample_count'] = '100';
        $evidence['restore_drill']['rto_hours'] = '0';
        $evidence['offsite_backup']['object_lock_enabled'] = false;
        $evidence['pilot']['unassisted_success_percent'] = 95;
        $evidence['approvals']['operations']['name'] = '   ';

        $checks = $this->runWithEvidence($evidence);

        foreach ([
            'evidence.schema_version',
            'evidence.release_sha_matches',
            'evidence.scanner.release_sha',
            'evidence.environment.ubuntu_version',
            'evidence.environment.php_version',
            'evidence.environment.database_path',
            'evidence.restore_drill.completed_at',
            'evidence.restore_drill.backup_checksum_sha256',
            'evidence.accessibility.keyboard',
            'evidence.performance.inp_samples',
            'evidence.performance.search_samples',
            'evidence.restore_drill.rto',
            'evidence.offsite_backup.object_lock_enabled',
            'evidence.pilot.success_calculation',
            'evidence.approvals.operations.name',
        ] as $name) {
            self::assertSame('failed', $checks[$name]['status'] ?? null, "Expected {$name} to fail.");
        }
    }

    public function test_empty_timestamps_are_not_interpreted_as_current_time(): void
    {
        $evidence = $this->validEvidence();
        $evidence['generated_at'] = '';
        $evidence['offsite_backup']['verified_at'] = '';
        $evidence['offsite_backup']['object_created_at'] = '';
        $evidence['approvals']['product']['approved_at'] = '';

        $checks = $this->runWithEvidence($evidence);

        self::assertSame('failed', $checks['evidence.generated_at']['status']);
        self::assertSame('failed', $checks['evidence.offsite_backup.verified_at']['status']);
        self::assertSame('failed', $checks['evidence.offsite_backup.object_created_at']['status']);
        self::assertSame('failed', $checks['evidence.offsite_backup.retention_until']['status']);
        self::assertSame('failed', $checks['evidence.approvals.product.approved_at']['status']);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, array{name: string, status: string, detail: string}>
     */
    private function runWithEvidence(array $evidence): array
    {
        $path = tempnam(sys_get_temp_dir(), 'project-desk-evidence-');
        self::assertIsString($path);

        try {
            file_put_contents($path, json_encode($evidence, JSON_THROW_ON_ERROR));
            config()->set('app.url', 'https://project-desk.example.com');
            config()->set('project-desk.operations.release_sha', str_repeat('a', 40));
            config()->set('project-desk.operations.production_evidence_path', $path);
            config()->set('database.connections.sqlite.database', database_path('database.sqlite'));

            Artisan::call('project-desk:production-readiness', ['--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertIsArray($payload['checks'] ?? null);

            return collect($payload['checks'])->keyBy('name')->all();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return array<string, mixed> */
    private function validEvidence(): array
    {
        $now = CarbonImmutable::now()->subMinute();
        $timestamp = $now->toIso8601String();
        $releaseSha = str_repeat('a', 40);
        $checksum = str_repeat('b', 64);

        return [
            'schema_version' => 2,
            'release_sha' => $releaseSha,
            'generated_at' => $timestamp,
            'environment' => [
                'name' => 'production',
                'base_url' => 'https://project-desk.example.com',
                'server_id' => 'project-desk-production-01',
                'ubuntu_version' => '24.04 LTS',
                'php_version' => '8.4.1',
                'database_path' => realpath(database_path('database.sqlite')),
            ],
            'scanner' => [
                'release_sha' => $releaseSha,
                'verified_at' => $timestamp,
                'clean' => ['passed' => true, 'activity_id' => 'scan-clean-1', 'checked_at' => $timestamp],
                'eicar' => ['passed' => true, 'activity_id' => 'scan-eicar-1', 'checked_at' => $timestamp],
                'outage' => ['passed' => true, 'activity_id' => 'scan-outage-1', 'checked_at' => $timestamp],
            ],
            'offsite_backup' => [
                'release_sha' => $releaseSha,
                'verified_at' => $timestamp,
                'provider' => 's3-compatible',
                'bucket' => 'project-desk-production',
                'object_key' => 'backups/example.pdesk',
                'object_version_id' => 'version-1',
                'object_lock_enabled' => true,
                'versioning_enabled' => true,
                'server_side_encryption_enabled' => true,
                'public_access_blocked' => true,
                'object_created_at' => $timestamp,
                'retention_until' => $now->addDays(36)->toIso8601String(),
                'rclone_check_passed' => true,
                'checksum_sha256' => $checksum,
            ],
            'services' => [
                'checked_at' => $timestamp,
                'queue_active' => true,
                'scheduler_active' => true,
                'backup_timer_active' => true,
                'monitor_timer_active' => true,
                'failed_jobs' => 0,
                'free_disk_bytes' => 10 * 1024 * 1024 * 1024,
                'nginx_config_valid' => true,
                'https_redirect' => true,
                'hsts' => true,
                'secure_cookie' => true,
            ],
            'restore_drill' => [
                'release_sha' => $releaseSha,
                'completed_at' => $timestamp,
                'rpo_hours' => 24,
                'rto_hours' => 4,
                'backup_checksum_sha256' => $checksum,
                'isolated_environment' => 'restore-drill-01',
                'login_passed' => true,
                'projects_passed' => true,
                'tasks_passed' => true,
                'documents_passed' => true,
                'files_passed' => true,
            ],
            'accessibility' => [
                'release_sha' => $releaseSha,
                'tested_at' => $timestamp,
                'approved_by' => 'Accessibility reviewer',
                'browsers' => ['Chrome', 'Firefox'],
                'screen_reader_name' => 'NVDA',
                'keyboard' => true,
                'screen_reader' => true,
                'bidi' => true,
                'zoom_200_percent' => true,
                'reduced_motion' => true,
            ],
            'performance' => [
                'release_sha' => $releaseSha,
                'measured_at' => $timestamp,
                'inp_p75_ms' => 200,
                'inp_sample_count' => 200,
                'search_p95_ms' => 500,
                'search_sample_count' => 100,
                'dataset_description' => '10,000 tasks, 1,000 requirements, 300-page specification, 12 sessions',
            ],
            'pilot' => [
                'release_sha' => $releaseSha,
                'completed_at' => $timestamp,
                'projects' => 2,
                'users' => 8,
                'journeys' => [
                    ['name' => 'Create and track a project', 'attempts' => 80, 'unassisted_successes' => 72],
                ],
                'unassisted_success_percent' => 90,
                'sus' => 75,
                'sus_response_count' => 8,
            ],
            'approvals' => [
                'product' => ['name' => 'Product owner', 'approved_at' => $timestamp],
                'technical' => ['name' => 'Technical owner', 'approved_at' => $timestamp],
                'operations' => ['name' => 'Operations owner', 'approved_at' => $timestamp],
            ],
        ];
    }
}
