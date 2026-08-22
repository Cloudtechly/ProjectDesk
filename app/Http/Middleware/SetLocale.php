<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string, array{code: string, dir: string, tag: string, label: string}> $supported */
        $supported = config('project-desk.localization.supported', []);
        $default = (string) config('project-desk.localization.default', 'ar');
        $cookieName = (string) config('project-desk.localization.cookie.name', 'project_desk_locale');
        $candidate = $request->cookie($cookieName);

        if (! isset($supported[$default])) {
            $default = 'ar';
        }

        $code = is_string($candidate) && isset($supported[$candidate])
            ? $candidate
            : $default;

        $current = $supported[$code] ?? [
            'code' => 'ar',
            'dir' => 'rtl',
            'tag' => 'ar',
            'label' => 'العربية',
        ];

        App::setLocale($current['code']);

        $request->attributes->set('project_desk.localization', [
            ...$current,
            'supported' => array_values($supported),
        ]);

        $response = $next($request);
        $responseLocale = $supported[App::currentLocale()] ?? $current;
        $response->headers->set('Content-Language', $responseLocale['tag']);

        return $response;
    }
}
