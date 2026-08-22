<?php

namespace App\Console\Commands;

use App\Models\DataJob;
use App\Models\User;
use App\Services\RestoreWriteFence;
use App\Services\SqliteBackupService;
use App\Services\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AutomaticBackup extends Command
{
    protected $signature = 'project-desk:automatic-backup
        {--force : إنشاء نسخة الآن حتى إذا كان الإعداد معطلاً أو لم يحن الموعد أو نُفذت نسخة في الفترة الحالية}';

    protected $description = 'إنشاء نسخة SQLite تلقائية عند حلول الموعد المعرّف في إعدادات Project Desk';

    public function handle(
        SystemSettingsService $settings,
        SqliteBackupService $backups,
        RestoreWriteFence $fence,
    ): int {
        try {
            $restoreLock = $fence->acquireShared();
        } catch (\RuntimeException) {
            $this->components->warn('الاستعادة قيد التنفيذ؛ تم تجاوز النسخ التلقائي في هذه الدورة.');

            return self::SUCCESS;
        }

        try {
            $force = (bool) $this->option('force');
            $automatic = $settings->group('automatic_backup');
            $enabled = ($automatic['enabled'] ?? false) === true;

            if (! $force && ! $enabled) {
                $this->components->info('النسخ التلقائي معطّل؛ لم تُنشأ نسخة.');

                return self::SUCCESS;
            }

            $frequency = $automatic['frequency'] ?? null;
            $time = $automatic['time'] ?? null;
            $retentionCount = $automatic['retention_count'] ?? null;
            $timezone = $settings->group('general')['timezone'] ?? null;
            $weekStart = $settings->group('calendar')['week_start'] ?? null;

            if (! is_string($frequency)
                || ! in_array($frequency, ['daily', 'weekly'], true)
                || ! is_string($time)
                || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1
                || ! is_int($retentionCount)
                || $retentionCount < 1
                || $retentionCount > 90
                || ! is_string($timezone)
                || ! in_array($timezone, timezone_identifiers_list(), true)
                || ! is_int($weekStart)
                || $weekStart < 0
                || $weekStart > 6) {
                $this->components->error('إعدادات النسخ التلقائي أو المنطقة الزمنية غير صالحة.');

                return self::FAILURE;
            }

            $now = CarbonImmutable::now($timezone);
            [$scheduledFor, $periodKey] = $this->period($now, $frequency, $time, $weekStart);
            if (! $force && $now->lessThan($scheduledFor)) {
                $this->components->info('لم يحن موعد النسخة التلقائية بعد.');

                return self::SUCCESS;
            }

            $lock = Cache::lock('project-desk:automatic-backup', 3600);
            if (! $lock->get()) {
                $this->components->warn('توجد عملية نسخ تلقائي قيد التنفيذ؛ تم تجاوز هذا التشغيل.');

                return self::SUCCESS;
            }

            try {
                if (! $force && $this->alreadyCompleted($periodKey)) {
                    $this->components->info('أُنجزت نسخة تلقائية لهذه الفترة مسبقاً؛ لم تُكرر.');

                    return self::SUCCESS;
                }

                $admin = User::query()
                    ->where('global_role', 'admin')
                    ->where('status', 'active')
                    ->whereNull('archived_at')
                    ->orderBy('id')
                    ->first();
                if (! $admin instanceof User) {
                    $this->components->error('لا يوجد مدير نشط يمكن تسجيل النسخة التلقائية باسمه.');

                    return self::FAILURE;
                }

                $job = $backups->create($admin, 'automatic', [
                    'automatic_period_key' => $periodKey,
                    'frequency' => $frequency,
                    'scheduled_for' => $scheduledFor->toIso8601String(),
                    'timezone' => $timezone,
                    'week_start' => $weekStart,
                    'retention_count' => $retentionCount,
                    'forced' => $force,
                ]);
                $pruned = $backups->pruneAutomaticBackups($retentionCount);
                $job->update([
                    'summary' => [
                        ...($job->summary ?? []),
                        'retention_pruned_count' => $pruned,
                    ],
                ]);
                $this->components->info("اكتملت النسخة التلقائية بنجاح (مهمة البيانات #{$job->id}).");

                return self::SUCCESS;
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error('فشل إنشاء النسخة التلقائية؛ راجع سجل التطبيق ومهمة البيانات الفاشلة.');

                return self::FAILURE;
            } finally {
                $this->release($lock);
            }
        } finally {
            $fence->release($restoreLock);
        }
    }

    /** @return array{CarbonImmutable, string} */
    private function period(
        CarbonImmutable $now,
        string $frequency,
        string $time,
        int $weekStart,
    ): array {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $periodStart = $now->startOfDay();
        if ($frequency === 'weekly') {
            $daysSinceWeekStart = ($now->dayOfWeek - $weekStart + 7) % 7;
            $periodStart = $periodStart->subDays($daysSinceWeekStart);
        }
        $scheduledFor = $periodStart->setTime($hour, $minute);
        $periodKey = implode(':', ['automatic', $frequency, $periodStart->format('Y-m-d')]);

        return [$scheduledFor, $periodKey];
    }

    private function alreadyCompleted(string $periodKey): bool
    {
        return DataJob::query()
            ->where('type', 'backup')
            ->whereIn('resource_type', ['database', 'system'])
            ->where('status', 'succeeded')
            ->where('summary->purpose', 'automatic')
            ->where('summary->automatic_period_key', $periodKey)
            ->exists();
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
