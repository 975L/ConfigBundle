<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VaultEncryptor
{
    private const MARKER = 'C975L:';
    private const ALGO   = 'aes-256-cbc';

    public function __construct(
        #[Autowire(env: 'default::C975L_VAULT_KEY')]
        private readonly ?string $vaultKey,
    ) {}

    // Encrypts a plain-text value and returns the stored format C975L:<base64(iv+ciphertext)>
    public function encrypt(string $plainValue): string
    {
        if ('' === $plainValue) {
            return '';
        }

        $key = $this->deriveKey();
        $iv  = random_bytes(16);

        $ciphertext = openssl_encrypt($plainValue, self::ALGO, $key, OPENSSL_RAW_DATA, $iv);
        if (false === $ciphertext) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::MARKER . base64_encode($iv . $ciphertext);
    }

    // Decrypts a stored C975L:<base64(iv+ciphertext)> value
    public function decrypt(string $encryptedValue): string
    {
        if ('' === $encryptedValue) {
            return '';
        }

        if (!$this->isEncrypted($encryptedValue)) {
            // Value not yet encrypted (pre-migration plaintext) — return as-is
            return $encryptedValue;
        }

        $key = $this->deriveKey();
        $raw = base64_decode(substr($encryptedValue, strlen(self::MARKER)));
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);

        $plain = openssl_decrypt($cipher, self::ALGO, $key, OPENSSL_RAW_DATA, $iv);

        if (false === $plain) {
            throw new \RuntimeException('Decryption failed. Check your C975L_VAULT_KEY.');
        }

        return $plain;
    }

    // Returns true if the value was produced by this encryptor
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::MARKER);
    }

    // Returns true if the vault key is defined
    public function isKeyDefined(): bool
    {
        return null !== $this->vaultKey && '' !== $this->vaultKey;
    }

    private function deriveKey(): string
    {
        if (!$this->isKeyDefined()) {
            throw new \RuntimeException('C975L_VAULT_KEY is not defined. Add it to your .env.local to handle sensitive settings.');
        }

        return hash('sha256', $this->vaultKey, true);
    }
}
