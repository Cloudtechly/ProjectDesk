<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class FileInventoryLock
{
    /**
     * Serialize complete-backup snapshots with destructive file retention so
     * a snapshot can never reference a blob that is being removed.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function block(Closure $callback, int $waitSeconds = 30): mixed
    {
        return Cache::lock('project-desk:file-inventory', 7200)
            ->block($waitSeconds, $callback);
    }
}
