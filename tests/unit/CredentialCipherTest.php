<?php

namespace Intercom\Tests;

use FKT\Component\Intercom\Administrator\Domain\CredentialCipher;
use PHPUnit\Framework\TestCase;

final class CredentialCipherTest extends TestCase
{
    public function testRoundTripAndRandomNonce(): void
    {
        $cipher = new CredentialCipher(str_repeat('x', 32));
        $a = $cipher->encrypt(['client_secret' => 'do-not-log', 'refresh_token' => 'rotating']);
        $b = $cipher->encrypt(['client_secret' => 'do-not-log', 'refresh_token' => 'rotating']);
        self::assertNotSame($a, $b);
        self::assertStringNotContainsString('do-not-log', $a);
        self::assertSame('rotating', $cipher->decrypt($a)['refresh_token']);
    }
    public function testWrongProviderFailsClosed(): void
    {
        $cipher = new CredentialCipher(str_repeat('x', 32));
        $envelope = $cipher->encrypt(['token' => 'test']);
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($envelope, 'postmark');
    }
    public function testChangedSiteSecretFailsClosed(): void
    {
        $envelope = (new CredentialCipher(str_repeat('x', 32)))->encrypt(['token' => 'test']);
        $this->expectException(\RuntimeException::class);
        (new CredentialCipher(str_repeat('y', 32)))->decrypt($envelope);
    }
    public function testTamperingFailsClosed(): void
    {
        $cipher = new CredentialCipher(str_repeat('x', 32));
        $data = json_decode($cipher->encrypt(['token' => 'test']), true);
        $raw = base64_decode($data['ciphertext']);
        $raw[0] = chr(ord($raw[0]) ^ 1);
        $data['ciphertext'] = base64_encode($raw);
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(json_encode($data));
    }
    public function testUnknownAlgorithmRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CredentialCipher(str_repeat('x', 32)))->decrypt('{"v":1,"alg":"none"}');
    }
}
