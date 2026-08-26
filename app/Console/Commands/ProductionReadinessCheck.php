<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class ProductionReadinessCheck extends Command
{
    protected $signature = 'project-desk:production-readiness {--json : إخراج النتيجة كـ JSON}';

    protected $description = 'فحص ضوابط تشغيل Project Desk التي يمكن التحقق منها آليًا قبل الإنتاج';

    /** @var list<array{name: string, status: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->check('environment.production', app()->environment('production'), 'APP_ENV must be production.');
        $this->check('debug.disabled', config('app.debug') === false, 'APP_DEBUG must be false.');
        $this->check('https.url', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL must use HTTPS.');
        $this->check('session.encrypted', config('session.encrypt') === true, 'SESSION_ENCRYPT must be true.');
        $this->check('session.secure_cookie', config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true.');
        $this->check('queue.database', config('queue.default') === 'database', 'QUEUE_CONNECTION must be database.');
        $this->check('database.sqlite', DB::connection()->getDriverName() === 'sqlite', 'The supported deployment uses SQLite.');
        $this->checkFailedJobs();
        $this->checkSqliteStorage();
        $this->checkBackupConfiguration();
        $this->checkMalwareScanner();
        $this->checkEvidenceFile();

        $failed = count(array_filter($this->results, fn (array $result): bool => $result['status'] === 'failed'));
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ready' => $failed === 0,
                'failed' => $failed,
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Check', 'Status', 'Detail'], $this->results);
            $failed === 0
                ? $this->components->info('All automated and evidence-backed production checks passed.')
                : $this->components->error("{$failed} production readiness check(s) failed.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkSqliteStorage(): void
    {
        $database = DB::connection()->getConfig('database');
        $path = is_string($database) ? realpath($database) : false;
        $localPath = is_string($path)
            && ! str_starts_with($path, '\\\\')
            && preg_match('/\A(?:smb|nfs):/i', $path) !== 1;
        $this->check('database.local_file', $localPath, 'SQLite must be a real file on local persistent storage.');

        $journal = DB::selectOne('PRAGMA journal_mode');
        $mode = is_object($journal) ? strtolower((string) ($journal->journal_mode ?? '')) : '';
        $this->check('database.wal', $mode === 'wal', "SQLite journal_mode is {$mode}; expected wal.");

        $minimumFreeBytes = (int) config('project-desk.operations.minimum_free_disk_bytes', 5 * 1024 * 1024 * 1024);
        $freeBytes = is_string($path) ? disk_free_space(dirname($path)) : false;
        $this->check(
            'database.free_space',
            is_float($freeBytes) && $freeBytes >= $minimumFreeBytes,
            is_float($freeBytes) ? "Free bytes: {$freeBytes}; required: {$minimumFreeBytes}." : 'Free space could not be measured.',
        );
    }

    private function checkBackupConfiguration(): void
    {
        $key = config('project-desk.data_center.backup_encryption_key');
        $this->check('backup.encryption_key', is_string($key) && trim($key) !== '', 'BACKUP_ENCRYPTION_KEY is required.');

        $remote = trim((string) config('project-desk.operations.rclone_remote'));
        $this->check('backup.off_host_remote', $remote !== '', 'RCLONE_REMOTE must name the encrypted/immutable off-host target.');
    }

    private function checkFailedJobs(): void
    {
        try {
            $failedJobs = DB::table('failed_jobs')->count();
            $this->check('queue.failed_jobs', $failedJobs === 0, "Failed jobs: {$failedJobs}.");
        } catch (Throwable $exception) {
            $this->check('queue.failed_jobs', false, 'The failed_jobs table could not be inspected: '.$exception->getMessage());
        }
    }

    private function checkMalwareScanner(): void
    {
        $driver = (string) config('project-desk.uploads.malware_scanner.driver');
        $executable = (string) config('project-desk.uploads.malware_scanner.executable');
        $resolved = (new ExecutableFinder)->find($executable);
        $this->check('scanner.driver', $driver === 'command', 'MALWARE_SCANNER_DRIVER must be command.');
        $this->check('scanner.executable', is_string($resolved), "Scanner executable {$executable} must be installed.");

        if (is_string($resolved)) {
            $process = new Process([$resolved, '--version']);
            $process->setTimeout(10);
            $process->run();
            $this->check('scanner.service', $process->isSuccessful(), 'clamdscan must reach the clamd service.');
        }

        $signaturePaths = (array) config('project-desk.operations.clamav_signature_paths', []);
        $newest = 0;
        foreach ($signaturePaths as $signaturePath) {
            if (is_string($signaturePath) && is_file($signaturePath)) {
                $modified = filemtime($signaturePath);
                $newest = is_int($modified) ? max($newest, $modified) : $newest;
            }
        }
        $maximumAge = (int) config('project-desk.operations.clamav_signature_max_age_seconds', 172800);
        $age = $newest > 0 ? time() - $newest : PHP_INT_MAX;
        $this->check('scanner.signatures_fresh', $age <= $maximumAge, "Newest signature age: {$age} seconds.");
    }

    private function checkEvidenceFile(): void
    {
        $path = (string) config('project-desk.operations.production_evidence_path');
        if ($path === '' || ! is_file($path)) {
            $this->check('evidence.file', false, 'A production evidence JSON file is required.');

            return;
        }

        try {
            $evidence = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->check('evidence.file', false, 'The production evidence file is not valid JSON.');

            return;
        }

        if (! is_array($evidence)) {
            $this->check('evidence.file', false, 'The production evidence root must be a JSON object.');

            return;
        }

        $this->check('evidence.file', true, 'The production evidence JSON file is readable and valid.');

        $releaseSha = trim((string) config('project-desk.operations.release_sha'));
        $expectedSchema = (int) config('project-desk.operations.production_evidence_schema_version', 2);
        $this->check('evidence.schema_version', data_get($evidence, 'schema_version') === $expectedSchema, "Evidence schema_version must be {$expectedSchema}.");
        $this->checkFreshTimestamp(
            'evidence.generated_at',
            data_get($evidence, 'generated_at'),
            (int) config('project-desk.operations.evidence_generated_max_age_hours', 24) * 3600,
        );
        $this->check('release.sha_configured', preg_match('/\A[a-f0-9]{40}\z/i', $releaseSha) === 1, 'APP_RELEASE_SHA must contain the deployed 40-character commit SHA.');
        $this->check('evidence.release_sha_matches', $releaseSha !== '' && data_get($evidence, 'release_sha') === $releaseSha, 'Evidence SHA must match APP_RELEASE_SHA.');
        $this->checkReleaseAlignment($evidence, $releaseSha);
        $this->checkEnvironmentEvidence($evidence);
        $this->checkScannerEvidence($evidence);
        $this->checkOffsiteBackupEvidence($evidence);
        $this->checkServiceEvidence($evidence);
        $this->checkRestoreEvidence($evidence);
        $this->checkAccessibilityEvidence($evidence);
        $this->checkPerformanceEvidence($evidence);
        $this->checkPilotEvidence($evidence);
        $this->checkApprovalEvidence($evidence);
    }

    /** @param array<string, mixed> $evidence */
    private function checkReleaseAlignment(array $evidence, string $releaseSha): void
    {
        foreach (['scanner', 'offsite_backup', 'restore_drill', 'accessibility', 'performance', 'pilot'] as $section) {
            $this->check(
                "evidence.{$section}.release_sha",
                $releaseSha !== '' && data_get($evidence, "{$section}.release_sha") === $releaseSha,
                "{$section}.release_sha must match APP_RELEASE_SHA.",
            );
        }
    }

    /** @param array<string, mixed> $evidence */
    private function checkEnvironmentEvidence(array $evidence): void
    {
        $this->check('evidence.environment.name', data_get($evidence, 'environment.name') === 'production', 'Environment evidence must identify production.');
        $this->check('evidence.environment.base_url', data_get($evidence, 'environment.base_url') === config('app.url'), 'Environment base_url must match APP_URL.');
        $this->checkNonEmptyString('evidence.environment.server_id', data_get($evidence, 'environment.server_id'));
        $this->check('evidence.environment.ubuntu_version', str_starts_with((string) data_get($evidence, 'environment.ubuntu_version'), '24.04'), 'Production evidence must identify Ubuntu 24.04 LTS.');
        $this->check('evidence.environment.php_version', str_starts_with((string) data_get($evidence, 'environment.php_version'), '8.4.'), 'Production evidence must identify PHP 8.4.x.');

        $connectionName = (string) config('database.default');
        $database = config("database.connections.{$connectionName}.database");
        $databasePath = is_string($database) ? realpath($database) : false;
        $this->check(
            'evidence.environment.database_path',
            is_string($databasePath) && data_get($evidence, 'environment.database_path') === $databasePath,
            'Evidence database_path must resolve to the configured SQLite database.',
        );
    }

    /** @param array<string, mixed> $evidence */
    private function checkScannerEvidence(array $evidence): void
    {
        $maximumAge = (int) config('project-desk.operations.scanner_evidence_max_age_days', 7) * 86400;
        $this->checkFreshTimestamp('evidence.scanner.verified_at', data_get($evidence, 'scanner.verified_at'), $maximumAge);

        foreach (['clean', 'eicar', 'outage'] as $case) {
            $this->check("evidence.scanner.{$case}.passed", data_get($evidence, "scanner.{$case}.passed") === true, "Scanner {$case} evidence must pass.");
            $this->checkNonEmptyString("evidence.scanner.{$case}.activity_id", data_get($evidence, "scanner.{$case}.activity_id"));
            $this->checkFreshTimestamp("evidence.scanner.{$case}.checked_at", data_get($evidence, "scanner.{$case}.checked_at"), $maximumAge);
        }
    }

    /** @param array<string, mixed> $evidence */
    private function checkOffsiteBackupEvidence(array $evidence): void
    {
        $maximumAge = (int) config('project-desk.operations.offsite_backup_evidence_max_age_hours', 26) * 3600;
        $this->checkFreshTimestamp('evidence.offsite_backup.verified_at', data_get($evidence, 'offsite_backup.verified_at'), $maximumAge);
        $this->checkFreshTimestamp('evidence.offsite_backup.object_created_at', data_get($evidence, 'offsite_backup.object_created_at'), $maximumAge);
        $this->check('evidence.offsite_backup.provider', in_array(data_get($evidence, 'offsite_backup.provider'), ['s3', 's3-compatible'], true), 'Off-host provider must be S3 or S3-compatible.');
        $this->checkNonEmptyString('evidence.offsite_backup.bucket', data_get($evidence, 'offsite_backup.bucket'));
        $this->checkNonEmptyString('evidence.offsite_backup.object_key', data_get($evidence, 'offsite_backup.object_key'));
        $this->checkNonEmptyString('evidence.offsite_backup.object_version_id', data_get($evidence, 'offsite_backup.object_version_id'));
        $this->checkSha256('evidence.offsite_backup.checksum_sha256', data_get($evidence, 'offsite_backup.checksum_sha256'));
        foreach (['object_lock_enabled', 'versioning_enabled', 'server_side_encryption_enabled', 'public_access_blocked'] as $field) {
            $this->check("evidence.offsite_backup.{$field}", data_get($evidence, "offsite_backup.{$field}") === true, "Off-host evidence {$field} must be true.");
        }
        $this->check('evidence.offsite_backup.rclone_check_passed', data_get($evidence, 'offsite_backup.rclone_check_passed') === true, 'rclone check must pass.');
        $this->checkRetentionWindow($evidence);
    }

    /** @param array<string, mixed> $evidence */
    private function checkRetentionWindow(array $evidence): void
    {
        $createdValue = data_get($evidence, 'offsite_backup.object_created_at');
        $retentionValue = data_get($evidence, 'offsite_backup.retention_until');
        $minimumDays = (int) config('project-desk.operations.offsite_retention_days', 35);

        try {
            $valid = is_string($createdValue)
                && trim($createdValue) !== ''
                && is_string($retentionValue)
                && trim($retentionValue) !== '';
            $createdAt = CarbonImmutable::parse((string) $createdValue);
            $retentionUntil = CarbonImmutable::parse((string) $retentionValue);
            $valid = $valid && $retentionUntil->greaterThanOrEqualTo($createdAt->addDays($minimumDays));
        } catch (Throwable) {
            $valid = false;
        }
        $this->check('evidence.offsite_backup.retention_until', $valid, "Object retention must cover at least {$minimumDays} days.");
    }

    /** @param array<string, mixed> $evidence */
    private function checkServiceEvidence(array $evidence): void
    {
        $maximumAge = (int) config('project-desk.operations.service_evidence_max_age_hours', 24) * 3600;
        $this->checkFreshTimestamp('evidence.services.checked_at', data_get($evidence, 'services.checked_at'), $maximumAge);
        foreach (['queue_active', 'scheduler_active', 'backup_timer_active', 'monitor_timer_active', 'nginx_config_valid', 'https_redirect', 'hsts', 'secure_cookie'] as $field) {
            $this->check("evidence.services.{$field}", data_get($evidence, "services.{$field}") === true, "Service evidence {$field} must be true.");
        }
        $this->check('evidence.services.failed_jobs', data_get($evidence, 'services.failed_jobs') === 0, 'Service evidence must report zero failed jobs.');
        $minimumFreeBytes = (int) config('project-desk.operations.minimum_free_disk_bytes', 5 * 1024 * 1024 * 1024);
        $this->checkIntegerRange(
            'evidence.services.free_disk_bytes',
            data_get($evidence, 'services.free_disk_bytes'),
            $minimumFreeBytes,
            PHP_INT_MAX,
            "Service evidence must report at least {$minimumFreeBytes} free bytes.",
        );
    }

    /** @param array<string, mixed> $evidence */
    private function checkRestoreEvidence(array $evidence): void
    {
        $maximumAge = (int) config('project-desk.operations.restore_evidence_max_age_days', 35) * 86400;
        $this->checkFreshTimestamp('evidence.restore_drill.completed_at', data_get($evidence, 'restore_drill.completed_at'), $maximumAge);
        $this->checkSha256('evidence.restore_drill.backup_checksum_sha256', data_get($evidence, 'restore_drill.backup_checksum_sha256'));
        $this->checkNonEmptyString('evidence.restore_drill.isolated_environment', data_get($evidence, 'restore_drill.isolated_environment'));
        $this->checkNumericRange('evidence.restore_drill.rpo', data_get($evidence, 'restore_drill.rpo_hours'), 0, 24, 'RPO must be between 0 and 24 hours.');
        $this->checkNumericRange('evidence.restore_drill.rto', data_get($evidence, 'restore_drill.rto_hours'), 0, 4, 'RTO must be between 0 and 4 hours.');
        foreach (['login_passed', 'projects_passed', 'tasks_passed', 'documents_passed', 'files_passed'] as $field) {
            $this->check("evidence.restore_drill.{$field}", data_get($evidence, "restore_drill.{$field}") === true, "Restore drill {$field} must be true.");
        }
    }

    /** @param array<string, mixed> $evidence */
    private function checkAccessibilityEvidence(array $evidence): void
    {
        $maximumAge = (int) config('project-desk.operations.accessibility_evidence_max_age_days', 35) * 86400;
        $this->checkFreshTimestamp('evidence.accessibility.tested_at', data_get($evidence, 'accessibility.tested_at'), $maximumAge);
        $this->checkNonEmptyString('evidence.accessibility.approved_by', data_get($evidence, 'accessibility.approved_by'));
        $this->checkNonEmptyString('evidence.accessibility.screen_reader_name', data_get($evidence, 'accessibility.screen_reader_name'));
        $browsers = data_get($evidence, 'accessibility.browsers', []);
        $this->check('evidence.accessibility.browsers', is_array($browsers) && in_array('Chrome', $browsers, true) && in_array('Firefox', $browsers, true), 'Accessibility evidence must cover Chrome and Firefox.');
        foreach (['keyboard', 'screen_reader', 'bidi', 'zoom_200_percent', 'reduced_motion'] as $field) {
            $this->check("evidence.accessibility.{$field}", data_get($evidence, "accessibility.{$field}") === true, "Accessibility check {$field} must pass.");
        }
    }

    /** @param array<string, mixed> $evidence */
    private function checkPerformanceEvidence(array $evidence): void
    {
        $maximumAge = (int) config('project-desk.operations.performance_evidence_max_age_days', 35) * 86400;
        $this->checkFreshTimestamp('evidence.performance.measured_at', data_get($evidence, 'performance.measured_at'), $maximumAge);
        $this->checkNonEmptyString('evidence.performance.dataset_description', data_get($evidence, 'performance.dataset_description'));
        $this->checkNumericRange('evidence.performance.inp', data_get($evidence, 'performance.inp_p75_ms'), 0, 200, 'p75 INP must be between 0 and 200 ms.');
        $this->checkIntegerRange('evidence.performance.inp_samples', data_get($evidence, 'performance.inp_sample_count'), (int) config('project-desk.operations.minimum_inp_samples', 200), PHP_INT_MAX, 'INP evidence has too few interaction samples.');
        $this->checkNumericRange('evidence.performance.search', data_get($evidence, 'performance.search_p95_ms'), 0, 500, 'Search p95 must be between 0 and 500 ms.');
        $this->checkIntegerRange('evidence.performance.search_samples', data_get($evidence, 'performance.search_sample_count'), (int) config('project-desk.operations.minimum_search_samples', 100), PHP_INT_MAX, 'Search evidence has too few samples.');
    }

    /** @param array<string, mixed> $evidence */
    private function checkPilotEvidence(array $evidence): void
    {
        $maximumAge = (int) config('project-desk.operations.pilot_evidence_max_age_days', 90) * 86400;
        $this->checkFreshTimestamp('evidence.pilot.completed_at', data_get($evidence, 'pilot.completed_at'), $maximumAge);
        $projects = data_get($evidence, 'pilot.projects');
        $users = data_get($evidence, 'pilot.users');
        $susResponses = data_get($evidence, 'pilot.sus_response_count');
        $this->checkIntegerRange('evidence.pilot.projects', $projects, 2, PHP_INT_MAX, 'Pilot requires at least two projects.');
        $this->checkIntegerRange('evidence.pilot.users', $users, 8, 12, 'Pilot requires 8-12 users.');
        $this->checkNumericRange('evidence.pilot.sus', data_get($evidence, 'pilot.sus'), 75, 100, 'SUS must be between 75 and 100.');
        $this->check(
            'evidence.pilot.sus_responses',
            is_int($users) && is_int($susResponses) && $users > 0 && $susResponses >= $users,
            'Every pilot user must submit a SUS response.',
        );

        $journeys = data_get($evidence, 'pilot.journeys', []);
        $attempts = 0;
        $successes = 0;
        $journeysValid = is_array($journeys) && $journeys !== [];
        if (is_array($journeys)) {
            foreach ($journeys as $journey) {
                $name = is_array($journey) ? trim((string) ($journey['name'] ?? '')) : '';
                $journeyAttempts = is_array($journey) ? ($journey['attempts'] ?? null) : null;
                $journeySuccesses = is_array($journey) ? ($journey['unassisted_successes'] ?? null) : null;
                $journeysValid = $journeysValid
                    && $name !== ''
                    && is_int($journeyAttempts)
                    && $journeyAttempts > 0
                    && is_int($journeySuccesses)
                    && $journeySuccesses >= 0
                    && $journeySuccesses <= $journeyAttempts;
                $attempts += is_int($journeyAttempts) ? max(0, $journeyAttempts) : 0;
                $successes += is_int($journeySuccesses) ? max(0, $journeySuccesses) : 0;
            }
        }
        $computedPercent = $attempts > 0 ? round(($successes / $attempts) * 100, 2) : 0.0;
        $reportedValue = data_get($evidence, 'pilot.unassisted_success_percent');
        $reportedPercent = is_int($reportedValue) || is_float($reportedValue) ? (float) $reportedValue : -1.0;
        $this->check('evidence.pilot.journeys', $journeysValid, 'Pilot must include valid raw journey counts.');
        $this->check('evidence.pilot.success_calculation', $journeysValid && abs($reportedPercent - $computedPercent) <= 0.1, 'Reported pilot success must match raw journeys.');
        $this->check('evidence.pilot.success', $computedPercent >= 90, 'Pilot unassisted success must be >= 90%.');
    }

    /** @param array<string, mixed> $evidence */
    private function checkApprovalEvidence(array $evidence): void
    {
        foreach (['product', 'technical', 'operations'] as $role) {
            $this->checkNonEmptyString("evidence.approvals.{$role}.name", data_get($evidence, "approvals.{$role}.name"));
            $this->checkValidTimestamp("evidence.approvals.{$role}.approved_at", data_get($evidence, "approvals.{$role}.approved_at"));
        }
    }

    private function checkNonEmptyString(string $name, mixed $value): void
    {
        $this->check($name, is_string($value) && trim($value) !== '', "{$name} must be a non-empty string.");
    }

    private function checkSha256(string $name, mixed $value): void
    {
        $this->check($name, is_string($value) && preg_match('/\A[a-f0-9]{64}\z/i', $value) === 1, "{$name} must be a SHA-256 checksum.");
    }

    private function checkIntegerRange(string $name, mixed $value, int $minimum, int $maximum, string $detail): void
    {
        $this->check($name, is_int($value) && $value >= $minimum && $value <= $maximum, $detail);
    }

    private function checkNumericRange(string $name, mixed $value, float $minimum, float $maximum, string $detail): void
    {
        $numeric = is_int($value) || is_float($value) ? (float) $value : null;
        $this->check($name, $numeric !== null && is_finite($numeric) && $numeric >= $minimum && $numeric <= $maximum, $detail);
    }

    private function checkFreshTimestamp(string $name, mixed $value, int $maximumAgeSeconds): void
    {
        try {
            if (! is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('Timestamp is empty.');
            }

            $timestamp = CarbonImmutable::parse((string) $value);
            $now = CarbonImmutable::now();
            $valid = $timestamp->lessThanOrEqualTo($now->addMinutes(5)) && $timestamp->greaterThanOrEqualTo($now->subSeconds($maximumAgeSeconds));
        } catch (Throwable) {
            $valid = false;
        }
        $this->check($name, $valid, "{$name} must be a valid timestamp no older than {$maximumAgeSeconds} seconds.");
    }

    private function checkValidTimestamp(string $name, mixed $value): void
    {
        try {
            if (! is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('Timestamp is empty.');
            }

            $timestamp = CarbonImmutable::parse((string) $value);
            $valid = $timestamp->lessThanOrEqualTo(CarbonImmutable::now()->addMinutes(5));
        } catch (Throwable) {
            $valid = false;
        }
        $this->check($name, $valid, "{$name} must be a valid timestamp that is not in the future.");
    }

    private function check(string $name, bool $passed, string $detail): void
    {
        $this->results[] = [
            'name' => $name,
            'status' => $passed ? 'passed' : 'failed',
            'detail' => $detail,
        ];
    }
}
