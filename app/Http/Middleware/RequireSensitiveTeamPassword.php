<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSensitiveTeamPassword
{
    public function __construct(private readonly RequirePassword $requirePassword) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $member = $request->route('member');
        if (! $actor instanceof User || ! $member instanceof User || ! $actor->is($member)) {
            return $next($request);
        }

        $changesEmail = $request->has('email') && $request->string('email')->toString() !== $member->email;
        $changesRole = $request->has('global_role') && $request->string('global_role')->toString() !== $member->global_role;
        $changesPassword = $request->filled('password');
        if (! $changesEmail && ! $changesRole && ! $changesPassword) {
            return $next($request);
        }

        return $this->requirePassword->handle($request, $next, 'password.confirm', 900);
    }
}
