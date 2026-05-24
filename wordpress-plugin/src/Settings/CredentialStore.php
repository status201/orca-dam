<?php

declare(strict_types=1);

namespace OrcaDam\Settings;

/**
 * Stores ORCA connection settings (base URL + Sanctum token) in wp_options.
 * The token is encrypted at rest; the base URL is not (it's not a secret).
 *
 * The token never leaves the server — it's used only by the WP-side OrcaClient
 * when proxying browser requests through to ORCA.
 */
class CredentialStore
{
    public const OPTION_BASE_URL = 'orca_dam_base_url';
    public const OPTION_TOKEN_ENC = 'orca_dam_token_encrypted';
    public const OPTION_DEFAULT_FOLDER = 'orca_dam_default_folder';

    public function __construct(private readonly Encryption $encryption) {}

    public function baseUrl(): string
    {
        return rtrim((string) get_option(self::OPTION_BASE_URL, ''), '/');
    }

    public function setBaseUrl(string $url): void
    {
        $url = esc_url_raw(rtrim($url, '/'));
        update_option(self::OPTION_BASE_URL, $url, false);
    }

    public function defaultFolder(): string
    {
        return (string) get_option(self::OPTION_DEFAULT_FOLDER, '');
    }

    public function setDefaultFolder(string $folder): void
    {
        update_option(self::OPTION_DEFAULT_FOLDER, sanitize_text_field($folder), false);
    }

    /**
     * Returns the decrypted Sanctum token, or null if unset.
     */
    public function token(): ?string
    {
        $encrypted = (string) get_option(self::OPTION_TOKEN_ENC, '');
        if ($encrypted === '') {
            return null;
        }

        try {
            return $this->encryption->decrypt($encrypted);
        } catch (\Throwable $e) {
            error_log('[orca-dam-picker] token decrypt failed: ' . $e->getMessage());
            return null;
        }
    }

    public function setToken(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            delete_option(self::OPTION_TOKEN_ENC);
            return;
        }
        update_option(self::OPTION_TOKEN_ENC, $this->encryption->encrypt($token), false);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->token() !== null;
    }
}
