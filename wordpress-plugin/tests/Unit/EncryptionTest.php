<?php

declare(strict_types=1);

namespace OrcaDam\Tests\Unit;

use OrcaDam\Settings\Encryption;
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase
{
    public function test_round_trips_token(): void
    {
        $enc = new Encryption();
        $cipher = $enc->encrypt('orca-sanctum-1|abcdef0123456789');
        $this->assertNotSame('orca-sanctum-1|abcdef0123456789', $cipher);
        $this->assertSame('orca-sanctum-1|abcdef0123456789', $enc->decrypt($cipher));
    }

    public function test_different_iv_each_encryption(): void
    {
        $enc = new Encryption();
        $a = $enc->encrypt('same-input');
        $b = $enc->encrypt('same-input');
        $this->assertNotSame($a, $b);
        $this->assertSame('same-input', $enc->decrypt($a));
        $this->assertSame('same-input', $enc->decrypt($b));
    }

    public function test_tampered_ciphertext_rejected(): void
    {
        $enc = new Encryption();
        $cipher = $enc->encrypt('confidential');
        $tampered = base64_encode(substr(base64_decode($cipher), 0, -1) . "\x00");
        $this->expectException(\RuntimeException::class);
        $enc->decrypt($tampered);
    }
}
