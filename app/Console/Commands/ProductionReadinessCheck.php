<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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

        $required = [
            'release_sha', 'restore_drill.completed_at', 'restore_drill.rpo_hours', 'restore_drill.rto_hours',
            'accessibility.approved_by', 'performance.inp_p75_ms', 'performance.search_p95_ms',
            'pilot.projects', 'pilot.users', 'pilot.unassisted_success_percent', 'pilot.sus',
            'approvals.product', 'approvals.technical', 'approvals.operations',
        ];
        foreach ($required as $key) {
            $this->check("evidence.{$key}", data_get($evidence, $key) !== null, "Evidence field {$key} is required.");
        }

        $releaseSha = trim((string) config('project-desk.operations.release_sha'));
        $this->check('release.sha_configured', preg_match('/\A[a-f0-9]{40}\z/i', $releaseSha) === 1, 'APP_RELEASE_SHA must contain the deployed 40-character commit SHA.');
        $this->check('evidence.release_sha_matches', $releaseSha !== '' && data_get($evidence, 'release_sha') === $releaseSha, 'Evidence SHA must match APP_RELEASE_SHA.');
        $this->check('evidence.rpo', (float) data_get($evidence, 'restore_drill.rpo_hours', INF) <= 24, 'RPO must be <= 24 hours.');
        $this->check('evidence.rto', (float) data_get($evidence, 'restore_drill.rto_hours', INF) <= 4, 'RTO must be <= 4 hours.');
        $this->check('evidence.inp', (float) data_get($evidence, 'performance.inp_p75_ms', INF) <= 200, 'p75 INP must be <= 200 ms.');
        $this->check('evidence.search', (float) data_get($evidence, 'performance.search_p95_ms', INF) <= 500, 'Search p95 must be <= 500 ms.');
        $this->check('evidence.pilot_projects', (int) data_get($evidence, 'pilot.projects', 0) >= 2, 'Pilot requires at least two projects.');
        $pilotUsers = (int) data_get($evidence, 'pilot.users', 0);
        $this->check('evidence.pilot_users', $pilotUsers >= 8 && $pilotUsers <= 12, 'Pilot requires 8-12 users.');
        $this->check('evidence.pilot_success', (float) data_get($evidence, 'pilot.unassisted_success_percent', 0) >= 90, 'Pilot success must be >= 90%.');
        $this->check('evidence.sus', (float) data_get($evidence, 'pilot.sus', 0) >= 75, 'SUS must be >= 75.');
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
