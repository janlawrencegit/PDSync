<?php

use PHPUnit\Framework\TestCase;

// Encryption at rest (lib/crypto.php): AES-256-GCM with a 32-byte key.
final class CryptoTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(32);
    }

    public function testSealedValueOpensWithTheSameKey(): void
    {
        $plain = json_encode(['fields' => ['surname' => 'DELA CRUZ', 'tin' => '123-456-789']]);
        $sealed = seal($plain, $this->key);

        $this->assertTrue(is_sealed($sealed));
        $this->assertStringNotContainsString('DELA CRUZ', $sealed);
        $this->assertSame($plain, unseal($sealed, $this->key));
    }

    public function testSameTextSealsDifferentlyEachTime(): void
    {
        // A fresh IV per value: equal answers do not produce equal ciphertext.
        $this->assertNotSame(seal('same', $this->key), seal('same', $this->key));
    }

    public function testValueSavedBeforeEncryptionIsReturnedAsIs(): void
    {
        $this->assertSame('{"fields":{}}', unseal('{"fields":{}}', $this->key));
        $this->assertFalse(is_sealed('{"fields":{}}'));
    }

    public function testWrongKeyIsRejected(): void
    {
        $sealed = seal('secret', $this->key);
        $this->expectException(RuntimeException::class);
        unseal($sealed, random_bytes(32));
    }

    public function testTamperedValueIsRejected(): void
    {
        $raw = base64_decode(substr(seal('secret answers', $this->key), strlen(SEAL_PREFIX)));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->expectException(RuntimeException::class);
        unseal(SEAL_PREFIX . base64_encode($raw), $this->key);
    }

    public function testTruncatedValueIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        unseal(SEAL_PREFIX . base64_encode('short'), $this->key);
    }
}
