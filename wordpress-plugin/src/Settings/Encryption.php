<?php

declare(strict_types=1);

namespace OrcaDam\Settings;

/**
 * AES-256-GCM round-trip for secrets. Key is sourced from the ORCA_ENCRYPTION_KEY
 * constant (or falls back to a per-install derived key from AUTH_KEY).
 *
 * Stored ciphertext format: base64(version || iv || tag || ciphertext)
 * where version is a single byte (currently 0x01).
 */
final class Encryption
{
    private const VERSION = "\x01";
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public function encrypt(string $plaintext): string
    {
        $key = $this->key();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('openssl_encrypt failed');
        }

        return base64_encode(self::VERSION . $iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 1 + self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('Encrypted payload is malformed');
        }
        if ($raw[0] !== self::VERSION) {
            throw new \RuntimeException('Unsupported encryption version');
        }

        $iv = substr($raw, 1, self::IV_LENGTH);
        $tag = substr($raw, 1 + self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, 1 + self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('openssl_decrypt failed');
        }

        return $plaintext;
    }

    private function key(): string
    {
        if (defined('ORCA_ENCRYPTION_KEY')) {
            $material = (string) constant('ORCA_ENCRYPTION_KEY');
        } elseif (defined('AUTH_KEY')) {
            $material = (string) constant('AUTH_KEY');
        } else {
            throw new \RuntimeException(
                'ORCA DAM Picker requires either ORCA_ENCRYPTION_KEY or AUTH_KEY to be defined in wp-config.php.'
            );
        }

        return hash('sha256', 'orca-dam-picker|' . $material, true);
    }
}
