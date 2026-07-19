<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\VaultEncryptor;
use PHPUnit\Framework\TestCase;

class VaultEncryptorTest extends TestCase
{
    public function testEncryptThenDecryptRoundTripsToTheOriginalPlainValue(): void
    {
        $encryptor = new VaultEncryptor('a-test-vault-key');

        $encrypted = $encryptor->encrypt('secret-api-key');

        $this->assertNotSame('secret-api-key', $encrypted);
        $this->assertSame('secret-api-key', $encryptor->decrypt($encrypted));
    }

    public function testEncryptPrefixesTheStoredValueWithTheMarker(): void
    {
        $encryptor = new VaultEncryptor('a-test-vault-key');

        $this->assertTrue($encryptor->isEncrypted($encryptor->encrypt('value')));
        $this->assertFalse($encryptor->isEncrypted('plain-value'));
    }

    // Two encryptions of the same value must differ (random IV), yet both decrypt back correctly
    public function testEncryptUsesARandomIvSoTwoEncryptionsOfTheSameValueDiffer(): void
    {
        $encryptor = new VaultEncryptor('a-test-vault-key');

        $first = $encryptor->encrypt('same-value');
        $second = $encryptor->encrypt('same-value');

        $this->assertNotSame($first, $second);
        $this->assertSame('same-value', $encryptor->decrypt($first));
        $this->assertSame('same-value', $encryptor->decrypt($second));
    }

    public function testEncryptAndDecryptReturnEmptyStringForEmptyInput(): void
    {
        $encryptor = new VaultEncryptor('a-test-vault-key');

        $this->assertSame('', $encryptor->encrypt(''));
        $this->assertSame('', $encryptor->decrypt(''));
    }

    // Values stored before the vault key was introduced (plain text) are returned unchanged
    public function testDecryptReturnsUnrecognizedValueAsIsWhenNotMarked(): void
    {
        $encryptor = new VaultEncryptor('a-test-vault-key');

        $this->assertSame('pre-migration-plaintext', $encryptor->decrypt('pre-migration-plaintext'));
    }

    // A malformed payload (a full 16-byte IV followed by ciphertext that isn't a multiple of the AES block size) makes openssl_decrypt() fail deterministically and without a PHP warning, unlike a merely "wrong key" case which could randomly still unpad successfully
    public function testDecryptWithMalformedPayloadThrowsRuntimeException(): void
    {
        $encryptor = new VaultEncryptor('a-test-vault-key');
        $malformed = 'C975L:' . base64_encode(str_repeat("\0", 16) . 'short');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Decryption failed');

        $encryptor->decrypt($malformed);
    }

    public function testEncryptWithoutVaultKeyThrowsRuntimeException(): void
    {
        $encryptor = new VaultEncryptor(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('C975L_VAULT_KEY is not defined');

        $encryptor->encrypt('value');
    }

    public function testIsKeyDefinedReflectsWhetherAVaultKeyWasProvided(): void
    {
        $this->assertTrue((new VaultEncryptor('a-key'))->isKeyDefined());
        $this->assertFalse((new VaultEncryptor(null))->isKeyDefined());
        $this->assertFalse((new VaultEncryptor(''))->isKeyDefined());
    }
}
