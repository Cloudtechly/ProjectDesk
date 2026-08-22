<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->validIdentifier($request->header('X-Request-Id')) ?? (string) Str::uuid();
        $correlationId = $this->validIdentifier($request->header('X-Correlation-Id')) ?? $requestId;

        $request->attributes->set('project_desk.request_id', $requestId);
        $request->attributes->set('project_desk.correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }

    private function validIdentifier(?string $value): ?string
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $value) === 1
                ? $value
                : null;
    }
}
