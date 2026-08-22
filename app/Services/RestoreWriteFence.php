<?php

namespace App\Services;

use Closure;
use RuntimeException;

class RestoreWriteFence
{
    /** @return resource */
    public function acquireShared()
    {
        if (app()->isDownForMaintenance()) {
            throw new RuntimeException('Project Desk is in maintenance mode for a system restore.');
        }

        $handle = $this->open();
        if (! flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('A Project Desk system restore is in progress.');
        }

        return $handle;
    }

    /** @param resource $handle */
    public function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Stop new HTTP and scheduled writes, wait for in-flight web requests to
     * finish, and keep the application unavailable until restore bookkeeping
     * has either committed or rolled back.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function exclusive(Closure $callback): mixed
    {
        $maintenance = app()->maintenanceMode();
        $wasAlreadyDown = $maintenance->active();

        if (! $wasAlreadyDown) {
            $maintenance->activate([
                'time' => time(),
                'retry' => 60,
                'status' => 503,
                'message' => 'Project Desk is restoring a verified recovery package.',
            ]);
        }

        $handle = $this->open();
        $locked = false;
        $deadline = microtime(true) + 30;

        try {
            do {
                $locked = flock($handle, LOCK_EX | LOCK_NB);
                if (! $locked) {
                    usleep(100_000);
                }
            } while (! $locked && microtime(true) < $deadline);

            if (! $locked) {
                throw new RuntimeException('Timed out waiting for active requests before restore.');
            }

            return $callback();
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);

            if (! $wasAlreadyDown) {
                $maintenance->deactivate();
            }
        }
    }

    /** @return resource */
    private function open()
    {
        $path = storage_path('framework/project-desk-restore.lock');
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('The restore lock directory could not be created.');
        }

        $handle = fopen($path, 'c+b');
        if (! is_resource($handle)) {
            throw new RuntimeException('The restore lock could not be opened.');
        }

        return $handle;
    }
}
