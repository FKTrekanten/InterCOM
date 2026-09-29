<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class CredentialCipher
{
    private string $key;

    public function __construct(#[\SensitiveParameter] string $siteSecret)
    {
        if (strlen($siteSecret) < 16) {
            throw new \RuntimeException('COM_INTERCOM_KEY_ERROR');
        }
        $this->key = hash_hkdf('sha256', $siteSecret, 32, 'com_intercom.credentials.v1');
    }

    private function aad(string $connection): string
    {
        return json_encode(['com_intercom', 'credentials', 1, 'xchacha20poly1305-ietf', $connection], JSON_THROW_ON_ERROR);
    }

    public function encrypt(#[\SensitiveParameter] array $values, string $connection = 'cleverreach'): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            json_encode($values, JSON_THROW_ON_ERROR),
            $this->aad($connection),
            $nonce,
            $this->key
        );
        return json_encode(['v' => 1, 'alg' => 'xchacha20poly1305-ietf', 'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext)], JSON_THROW_ON_ERROR);
    }

    public function decrypt(#[\SensitiveParameter] string $envelope, string $connection = 'cleverreach'): array
    {
        try {
            $data = json_decode($envelope, true, 16, JSON_THROW_ON_ERROR);
            if (($data['v'] ?? null) !== 1 || ($data['alg'] ?? '') !== 'xchacha20poly1305-ietf') {
                throw new \RuntimeException();
            }
            $nonce = base64_decode($data['nonce'], true);
            $ciphertext = base64_decode($data['ciphertext'], true);
            if ($nonce === false || strlen($nonce) !== 24 || $ciphertext === false || strlen($ciphertext) < 16) {
                throw new \RuntimeException();
            }
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $this->aad($connection), $nonce, $this->key);
            if ($plain === false) {
                throw new \RuntimeException();
            }
            $result = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result)) {
                throw new \RuntimeException();
            }
            return $result;
        } catch (\Throwable) {
            throw new \RuntimeException('COM_INTERCOM_DECRYPT_ERROR');
        }
    }
}
