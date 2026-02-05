<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
    }

    /**
     * Generate a new TOTP secret for the user
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Get the QR code URL for the secret
     */
    public function getQrCodeUrl(User $user, string $secret): string
    {
        $issuer = config('two-factor.issuer', config('app.name', 'ORCA DAM'));

        return $this->google2fa->getQRCodeUrl(
            $issuer,
            $user->email,
            $secret
        );
    }

    /**
     * Generate QR code SVG for the secret
     */
    public function getQrCodeSvg(User $user, string $secret): string
    {
        $qrCodeUrl = $this->getQrCodeUrl($user, $secret);
        $size = config('two-factor.qr_code_size', 200);

        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd
        );

        $writer = new Writer($renderer);

        return $writer->writeString($qrCodeUrl);
    }

    /**
     * Verify a TOTP code against a secret
     */
    public function verifyCode(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Generate recovery codes for the user
     */
    public function generateRecoveryCodes(): array
    {
        $count = config('two-factor.recovery_codes_count', 8);
        $length = config('two-factor.recovery_code_length', 10);

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateRecoveryCode($length);
        }

        return $codes;
    }

    /**
     * Generate a single recovery code
     */
    protected function generateRecoveryCode(int $length): string
    {
        // Generate a code with segments for readability (e.g., XXXXX-XXXXX)
        $halfLength = (int) floor($length / 2);
        $firstHalf = strtoupper(Str::random($halfLength));
        $secondHalf = strtoupper(Str::random($length - $halfLength));

        return $firstHalf.'-'.$secondHalf;
    }

    /**
     * Hash recovery codes for storage
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn ($code) => Hash::make($code), $codes);
    }

    /**
     * Verify a recovery code against hashed codes
     * Returns the index of the matched code or false if not found
     */
    public function verifyRecoveryCode(string $code, array $hashedCodes): int|false
    {
        foreach ($hashedCodes as $index => $hashedCode) {
            if (Hash::check($code, $hashedCode)) {
                return $index;
            }
        }

        return false;
    }

    /**
     * Enable 2FA for a user
     */
    public function enableTwoFactor(User $user, string $secret): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();
        $hashedCodes = $this->hashRecoveryCodes($recoveryCodes);

        $user->update([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $hashedCodes,
            'two_factor_confirmed_at' => now(),
        ]);

        return $recoveryCodes;
    }

    /**
     * Disable 2FA for a user
     */
    public function disableTwoFactor(User $user): void
    {
        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    /**
     * Regenerate recovery codes for a user
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $recoveryCodes = $this->generateRecoveryCodes();
        $hashedCodes = $this->hashRecoveryCodes($recoveryCodes);

        $user->update([
            'two_factor_recovery_codes' => $hashedCodes,
        ]);

        return $recoveryCodes;
    }

    /**
     * Use a recovery code (removes it from the list)
     */
    public function useRecoveryCode(User $user, int $codeIndex): void
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        unset($codes[$codeIndex]);

        $user->update([
            'two_factor_recovery_codes' => array_values($codes),
        ]);
    }

    /**
     * Get the count of remaining recovery codes for a user
     */
    public function getRemainingRecoveryCodesCount(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }
}
