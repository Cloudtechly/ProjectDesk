<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSensitiveProfilePassword
{
    public function __construct(private readonly RequirePassword $requirePassword) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User
            || ! $request->has('email')
            || $request->string('email')->toString() === $user->email) {
            return $next($request);
        }

        return $this->requirePassword->handle($request, $next, 'password.confirm', 900);
    }
}
