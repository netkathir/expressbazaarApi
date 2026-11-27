<?php

namespace App\Support;

use RuntimeException;

class WebhookSecretCrypt
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('Unable to encrypt webhook secret.');
        }

        return implode(':', [
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    public static function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 3);

        if (count($parts) !== 3) {
            throw new RuntimeException('Webhook secret payload is malformed.');
        }

        [$encodedIv, $encodedTag, $encodedCiphertext] = $parts;
        $iv = base64_decode($encodedIv, true);
        $tag = base64_decode($encodedTag, true);
        $ciphertext = base64_decode($encodedCiphertext, true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            throw new RuntimeException('Webhook secret payload is not valid base64.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt webhook secret.');
        }

        return $plaintext;
    }

    private static function key(): string
    {
        $configured = trim((string) config('services.webhook_encryption_key', ''));

        if ($configured === '') {
            throw new RuntimeException('WEBHOOK_ENC_KEY is not configured.');
        }

        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);

            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        if (ctype_xdigit($configured) && strlen($configured) === 64) {
            $decoded = hex2bin($configured);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        if (strlen($configured) === 32) {
            return $configured;
        }

        throw new RuntimeException('WEBHOOK_ENC_KEY must be a 32-byte raw string, base64: value, or 64-character hex string.');
    }
}
