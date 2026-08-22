<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

final class ApplicationKeyEnsurer
{
    public function ensure(string $environmentFilePath, string $cipher): bool
    {
        $handle = @fopen($environmentFilePath, 'r+');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the environment file: {$environmentFilePath}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the environment file.');
            }

            if (rewind($handle) === false) {
                throw new RuntimeException('Unable to read the environment file.');
            }

            $contents = stream_get_contents($handle);

            if ($contents === false) {
                throw new RuntimeException('Unable to read the environment file.');
            }

            $matchCount = preg_match_all(
                '/^(?<prefix>[ \t]*APP_KEY[ \t]*=[ \t]*)(?<value>[^\r\n]*)$/m',
                $contents,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            if ($matchCount !== 1) {
                throw new RuntimeException('The environment file must contain exactly one APP_KEY entry.');
            }

            $match = $matches[0];
            $currentValue = $match['value'][0];

            if (! $this->isEmptyValue($currentValue)) {
                return false;
            }

            $key = 'base64:'.base64_encode(Encrypter::generateKey($cipher));
            $fullMatch = $match[0][0];
            $fullMatchOffset = $match[0][1];
            $prefix = $match['prefix'][0];

            $updatedContents = substr_replace(
                $contents,
                $prefix.$key,
                $fullMatchOffset,
                strlen($fullMatch),
            );

            if (rewind($handle) === false || ! ftruncate($handle, 0)) {
                throw new RuntimeException('Unable to update the environment file.');
            }

            $remaining = $updatedContents;

            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to update the environment file.');
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($handle)) {
                throw new RuntimeException('Unable to flush the environment file.');
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function isEmptyValue(string $value): bool
    {
        $value = trim($value);

        return $value === ''
            || str_starts_with($value, '#')
            || preg_match('/^(?:""|\'\')[ \t]*(?:#.*)?$/', $value) === 1;
    }
}
