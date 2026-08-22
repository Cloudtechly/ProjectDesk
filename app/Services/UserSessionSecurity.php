<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserSessionSecurity
{
    public function invalidateFor(User $user, ?string $exceptSessionId = null): void
    {
        $connection = DB::connection(config('session.connection'));
        $table = (string) config('session.table', 'sessions');
        if ($connection->getSchemaBuilder()->hasTable($table)) {
            $query = $connection->table($table)->where('user_id', $user->id);
            if (is_string($exceptSessionId) && $exceptSessionId !== '') {
                $query->where('id', '!=', $exceptSessionId);
            }
            $query->delete();
        }

        $user->setRememberToken(Str::random(60));
        $user->saveQuietly();
    }

    public function refreshCurrentPasswordHash(Request $request, User $user): void
    {
        $request->session()->put('password_hash_'.config('auth.defaults.guard', 'web'), $user->getAuthPassword());
    }

    public function purgeRestoredAuthenticationState(): void
    {
        $connection = DB::connection();
        $schema = $connection->getSchemaBuilder();
        if ($schema->hasTable('sessions')) {
            $connection->table('sessions')->delete();
        }
        if ($schema->hasTable('password_reset_tokens')) {
            $connection->table('password_reset_tokens')->delete();
        }

        User::query()->select('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
            }
        });
    }
}
