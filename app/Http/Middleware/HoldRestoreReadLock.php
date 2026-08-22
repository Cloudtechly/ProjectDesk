<?php

namespace App\Http\Middleware;

use App\Services\RestoreWriteFence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HoldRestoreReadLock
{
    public function __construct(private readonly RestoreWriteFence $fence) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('data-center.backups.restore')) {
            return $next($request);
        }

        try {
            $lock = $this->fence->acquireShared();
        } catch (\RuntimeException) {
            abort(423, 'النظام متوقف مؤقتاً لإتمام استعادة آمنة. حاول مجدداً بعد دقيقة.');
        }

        try {
            return $next($request);
        } finally {
            $this->fence->release($lock);
        }
    }
}
