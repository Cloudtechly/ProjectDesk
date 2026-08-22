<?php

namespace App\Services;

use App\Models\FileObject;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class RestoreNonceManager
{
    public function issue(User $actor, FileObject $backup): string
    {
        $nonce = bin2hex(random_bytes(32));
        $payload = [
            'user_id' => $actor->id,
            'backup_id' => $backup->id,
            'checksum_sha256' => $backup->checksum_sha256,
        ];
        Cache::put($this->key($nonce), $payload, now()->addSeconds(max(60, (int) config('project-desk.data_center.restore_nonce_ttl_seconds', 600))));

        return $nonce.'.'.$this->signature($payload);
    }

    public function consume(
        User $actor,
        FileObject $backup,
        string $checksum,
        string $nonce,
    ): void {
        [$identifier, $signature] = array_pad(explode('.', $nonce, 2), 2, '');
        try {
            $payload = Cache::lock($this->key($identifier).':consume', 10)
                ->block(2, fn () => Cache::pull($this->key($identifier)));
        } catch (LockTimeoutException) {
            $payload = null;
        }

        if (! is_array($payload)
            || ! hash_equals($this->signature($payload), $signature)
            || (int) ($payload['user_id'] ?? 0) !== $actor->id
            || (int) ($payload['backup_id'] ?? 0) !== $backup->id
            || ! hash_equals((string) ($payload['checksum_sha256'] ?? ''), $checksum)) {
            throw ValidationException::withMessages([
                'restore_nonce' => 'انتهت صلاحية تفويض الاستعادة أو تم استخدامه. أعد فحص النسخة.',
            ]);
        }
    }

    private function key(string $nonce): string
    {
        return 'project-desk:restore-nonce:'.hash('sha256', $nonce);
    }

    private function signingKey(): string
    {
        return hash('sha256', (string) config('app.key'));
    }

    /** @param array<string, mixed> $payload */
    private function signature(array $payload): string
    {
        return hash_hmac('sha256', implode('|', [
            (string) ($payload['user_id'] ?? ''),
            (string) ($payload['backup_id'] ?? ''),
            (string) ($payload['checksum_sha256'] ?? ''),
        ]), $this->signingKey());
    }
}
