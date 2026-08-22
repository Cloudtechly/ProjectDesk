<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class OptimisticLock
{
    public static function assertCurrent(int $actualVersion, int $expectedVersion): void
    {
        if ($actualVersion === $expectedVersion) {
            return;
        }

        throw ValidationException::withMessages([
            'lock_version' => 'تم تعديل هذا السجل بواسطة مستخدم آخر. حدّث الصفحة ثم أعد المحاولة.',
        ]);
    }
}
