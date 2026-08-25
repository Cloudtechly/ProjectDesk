<?php

namespace App\Services;

use JsonException;
use RuntimeException;

class BackupBundleCryptographer
{
    private const ENCRYPTED_FORMAT = 'project-desk-encrypted-backup';

    private const VERSION = 1;

    private const MAGIC = "PDBK\x01\r\n\x00";

    private const CIPHER = 'aes-256-gcm-chunked';

    private const CHUNK_BYTES = 1024 * 1024;

    private const TAG_BYTES = 16;

    private const MAX_HEADER_BYTES = 64 * 1024;

    public function cipher(): string
    {
        return self::CIPHER;
    }

    public function isEncryptedBackup(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            return false;
        }

        try {
            return hash_equals(self::MAGIC, (string) fread($stream, strlen(self::MAGIC)));
        } finally {
            fclose($stream);
        }
    }

    /** @return array{key_id: string, checksum_sha256: string, size_bytes: int} */
    public function encrypt(string $sourcePath, string $destinationPath): array
    {
        $key = $this->activeEncryptionKey();
        $keyId = $this->keyId($key);
        $plaintextChecksum = hash_file('sha256', $sourcePath);
        $plaintextSize = filesize($sourcePath);
        if (! is_string($plaintextChecksum) || ! is_int($plaintextSize) || $plaintextSize <= 0) {
            throw new RuntimeException('The backup payload could not be measured before encryption.');
        }
        $noncePrefix = random_bytes(8);
        $header = [
            'format' => self::ENCRYPTED_FORMAT,
            'version' => self::VERSION,
            'cipher' => self::CIPHER,
            'key_id' => $keyId,
            'chunk_size' => self::CHUNK_BYTES,
            'nonce_prefix' => base64_encode($noncePrefix),
            'plaintext_size' => $plaintextSize,
            'plaintext_sha256' => $plaintextChecksum,
            'created_at' => now()->toIso8601String(),
        ];
        $headerJson = json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($headerJson) > self::MAX_HEADER_BYTES) {
            throw new RuntimeException('The encrypted backup header is too large.');
        }
        $aadPrefix = hash('sha256', self::MAGIC.$headerJson, true);

        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'xb');
        if (! is_resource($source) || ! is_resource($destination)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            throw new RuntimeException('The encrypted backup file could not be opened.');
        }

        try {
            $this->writeAll($destination, self::MAGIC.pack('N', strlen($headerJson)).$headerJson);
            $index = 0;
            while (! feof($source)) {
                $plaintext = fread($source, self::CHUNK_BYTES);
                if ($plaintext === false) {
                    throw new RuntimeException('The backup payload could not be read during encryption.');
                }
                if ($plaintext === '') {
                    break;
                }
                if ($index > 0xFFFFFFFE) {
                    throw new RuntimeException('The backup contains too many encryption chunks.');
                }

                $recordHeader = pack('NCN', $index, 0, strlen($plaintext));
                $tag = '';
                $ciphertext = openssl_encrypt(
                    $plaintext,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $noncePrefix.pack('N', $index),
                    $tag,
                    $aadPrefix.$recordHeader,
                    self::TAG_BYTES,
                );
                if (! is_string($ciphertext) || strlen($tag) !== self::TAG_BYTES) {
                    throw new RuntimeException('A backup encryption chunk failed.');
                }
                $this->writeAll($destination, $recordHeader.$tag.$ciphertext);
                $index++;
            }

            $finalHeader = pack('NCN', $index, 1, 0);
            $finalTag = '';
            $finalCiphertext = openssl_encrypt(
                '',
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $noncePrefix.pack('N', $index),
                $finalTag,
                $aadPrefix.$finalHeader,
                self::TAG_BYTES,
            );
            if ($finalCiphertext !== '' || strlen($finalTag) !== self::TAG_BYTES) {
                throw new RuntimeException('The backup encryption terminator failed.');
            }
            $this->writeAll($destination, $finalHeader.$finalTag);
            fflush($destination);
        } finally {
            fclose($source);
            fclose($destination);
        }
        @chmod($destinationPath, 0600);

        $checksum = hash_file('sha256', $destinationPath);
        $size = filesize($destinationPath);
        if (! is_string($checksum) || ! is_int($size) || $size <= 0) {
            throw new RuntimeException('The encrypted backup checksum could not be calculated.');
        }

        return ['key_id' => $keyId, 'checksum_sha256' => $checksum, 'size_bytes' => $size];
    }

    /** @return array{cipher: string, key_id: string, plaintext_size: int, plaintext_checksum_sha256: string} */
    public function decrypt(string $sourcePath, string $destinationPath): array
    {
        $source = fopen($sourcePath, 'rb');
        $destination = fopen($destinationPath, 'xb');
        if (! is_resource($source) || ! is_resource($destination)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            throw new RuntimeException('The encrypted backup could not be opened for verification.');
        }

        try {
            $magic = $this->readExact($source, strlen(self::MAGIC));
            if (! hash_equals(self::MAGIC, $magic)) {
                throw new RuntimeException('The file is not a Project Desk encrypted backup.');
            }
            $headerLength = unpack('Nlength', $this->readExact($source, 4));
            $headerBytes = (int) ($headerLength['length'] ?? 0);
            if ($headerBytes <= 0 || $headerBytes > self::MAX_HEADER_BYTES) {
                throw new RuntimeException('The encrypted backup header length is invalid.');
            }
            $headerJson = $this->readExact($source, $headerBytes);
            $header = json_decode($headerJson, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($header)
                || ($header['format'] ?? null) !== self::ENCRYPTED_FORMAT
                || ($header['version'] ?? null) !== self::VERSION
                || ($header['cipher'] ?? null) !== self::CIPHER
                || ! is_string($header['key_id'] ?? null)
                || ! is_int($header['chunk_size'] ?? null)
                || $header['chunk_size'] < 64 * 1024
                || $header['chunk_size'] > 8 * 1024 * 1024
                || ! is_int($header['plaintext_size'] ?? null)
                || $header['plaintext_size'] <= 0
                || $header['plaintext_size'] > $this->maximumExpandedBytes()
                || ! is_string($header['plaintext_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $header['plaintext_sha256']) !== 1) {
                throw new RuntimeException('The encrypted backup header is invalid.');
            }

            $noncePrefix = base64_decode((string) ($header['nonce_prefix'] ?? ''), true);
            if (! is_string($noncePrefix) || strlen($noncePrefix) !== 8) {
                throw new RuntimeException('The encrypted backup nonce is invalid.');
            }
            $key = $this->decryptionKey((string) $header['key_id']);
            $aadPrefix = hash('sha256', self::MAGIC.$headerJson, true);
            $hash = hash_init('sha256');
            $written = 0;
            $expectedIndex = 0;

            while (true) {
                $recordHeader = $this->readExact($source, 9);
                $record = unpack('Nindex/Cflags/Nlength', $recordHeader);
                $index = (int) ($record['index'] ?? -1);
                $flags = (int) ($record['flags'] ?? -1);
                $length = (int) ($record['length'] ?? -1);
                if ($index !== $expectedIndex
                    || ! in_array($flags, [0, 1], true)
                    || $length < 0
                    || $length > (int) $header['chunk_size']
                    || ($flags === 0 && $length === 0)
                    || ($flags === 1 && $length !== 0)) {
                    throw new RuntimeException('The encrypted backup chunk sequence is invalid.');
                }

                $tag = $this->readExact($source, self::TAG_BYTES);
                $ciphertext = $this->readExact($source, $length);
                $plaintext = openssl_decrypt(
                    $ciphertext,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $noncePrefix.pack('N', $index),
                    $tag,
                    $aadPrefix.$recordHeader,
                );
                if (! is_string($plaintext)) {
                    throw new RuntimeException('The encrypted backup authentication tag is invalid.');
                }
                if ($flags === 1) {
                    if ($plaintext !== '' || fread($source, 1) !== '') {
                        throw new RuntimeException('The encrypted backup contains trailing data.');
                    }

                    break;
                }

                $this->writeAll($destination, $plaintext);
                hash_update($hash, $plaintext);
                $written += strlen($plaintext);
                if ($written > (int) $header['plaintext_size']) {
                    throw new RuntimeException('The decrypted backup is larger than its authenticated header.');
                }
                $expectedIndex++;
            }
            fflush($destination);

            $checksum = hash_final($hash);
            if ($written !== (int) $header['plaintext_size']
                || ! hash_equals((string) $header['plaintext_sha256'], $checksum)) {
                throw new RuntimeException('The decrypted backup checksum is invalid.');
            }

            return [
                'cipher' => self::CIPHER,
                'key_id' => (string) $header['key_id'],
                'plaintext_size' => $written,
                'plaintext_checksum_sha256' => $checksum,
            ];
        } catch (JsonException $exception) {
            throw new RuntimeException('The encrypted backup header is not valid JSON.', previous: $exception);
        } finally {
            fclose($source);
            fclose($destination);
        }
    }

    private function activeEncryptionKey(): string
    {
        $configured = config('project-desk.data_center.backup_encryption_key');
        if (is_string($configured) && trim($configured) !== '') {
            return $this->decodeKey($configured);
        }

        if (app()->environment(['local', 'testing'])) {
            $applicationKey = (string) config('app.key');
            if ($applicationKey === '') {
                throw new RuntimeException('BACKUP_ENCRYPTION_KEY is not configured and APP_KEY is unavailable.');
            }
            $raw = str_starts_with($applicationKey, 'base64:')
                ? base64_decode(substr($applicationKey, 7), true)
                : $applicationKey;
            if (! is_string($raw) || $raw === '') {
                throw new RuntimeException('The local APP_KEY fallback is invalid.');
            }

            return hash_hkdf('sha256', $raw, 32, 'project-desk-backup-v1');
        }

        throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be configured outside local/testing environments.');
    }

    private function decryptionKey(string $keyId): string
    {
        $keys = [$this->activeEncryptionKey()];
        $previous = config('project-desk.data_center.backup_previous_encryption_keys', []);
        if (is_array($previous)) {
            foreach ($previous as $configured) {
                if (is_string($configured) && trim($configured) !== '') {
                    $keys[] = $this->decodeKey($configured);
                }
            }
        }
        foreach ($keys as $key) {
            if (hash_equals($keyId, $this->keyId($key))) {
                return $key;
            }
        }

        throw new RuntimeException('The encryption key required for this backup is not configured.');
    }

    private function decodeKey(string $configured): string
    {
        $encoded = str_starts_with($configured, 'base64:') ? substr($configured, 7) : $configured;
        $key = base64_decode($encoded, true);
        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        return $key;
    }

    private function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    private function maximumExpandedBytes(): int
    {
        return max(
            1024 * 1024,
            (int) config('project-desk.data_center.backup_max_expanded_kilobytes', 2 * 1024 * 1024) * 1024,
        );
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (! is_int($written) || $written <= 0) {
                throw new RuntimeException('A backup stream could not be written completely.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('The encrypted backup contains a negative record length.');
        }
        if ($length === 0) {
            return '';
        }
        $buffer = '';
        while (strlen($buffer) < $length) {
            $remaining = $length - strlen($buffer);
            if ($remaining <= 0) {
                break;
            }
            $chunk = fread($stream, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('The encrypted backup is truncated.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
