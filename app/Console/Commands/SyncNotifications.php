<?php

namespace App\Console\Commands;

use App\Services\NotificationCenterService;
use App\Services\RestoreWriteFence;
use Illuminate\Console\Command;

class SyncNotifications extends Command
{
    protected $signature = 'project-desk:sync-notifications';

    protected $description = 'مزامنة تنبيهات المهام والاجتماعات الدائمة مع المواعيد والصلاحيات الحالية';

    public function handle(NotificationCenterService $notifications, RestoreWriteFence $fence): int
    {
        try {
            $restoreLock = $fence->acquireShared();
        } catch (\RuntimeException) {
            $this->components->warn('الاستعادة قيد التنفيذ؛ تم تجاوز مزامنة التنبيهات في هذه الدورة.');

            return self::SUCCESS;
        }

        try {
            $summary = $notifications->sync();

            $this->components->info(sprintf(
                'اكتملت مزامنة التنبيهات: %d مستخدم، %d جديد، %d محدث، %d ملغي.',
                $summary['users'],
                $summary['created'],
                $summary['updated'],
                $summary['deleted'],
            ));

            return self::SUCCESS;
        } finally {
            $fence->release($restoreLock);
        }
    }
}
