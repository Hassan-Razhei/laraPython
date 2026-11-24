<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Exceptions\InvalidSignedStreamUrlException;
use EntityStreamer\Security\StreamUrlSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class StreamUrlSignerTest extends TestCase
{
    private StreamUrlSigner $signer;
    private string $secret = 'test_secret_key_32_bytes_long_12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signer = new StreamUrlSigner(secretKey: $this->secret);
    }

    public function test_signs_and_validates_media_url(): void
    {
        $path = 'videos/lecture-01.mp4';
        $signedUrl = $this->signer->sign($path, expiration: 3600); // Valid for 1 hour

        $this->assertStringContainsString('signature=', $signedUrl);
        $this->assertStringContainsString('expires=', $signedUrl);

        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

        $isValid = $this->signer->validate($path, $query);
        $this->assertTrue($isValid);
    }

    public function test_rejects_expired_signature(): void
    {
        $path = 'videos/lecture-01.mp4';
        // Expired 100 seconds ago
        $signedUrl = $this->signer->sign($path, expiration: time() - 100);

        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

        $this->expectException(InvalidSignedStreamUrlException::class);
        $this->signer->validate($path, $query);
    }

    public function test_rejects_tampered_signature_or_path(): void
    {
        $path = 'videos/lecture-01.mp4';
        $signedUrl = $this->signer->sign($path, expiration: 3600);

        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

        // Attempting to validate a different path with the same signature
        $this->expectException(InvalidSignedStreamUrlException::class);
        $this->signer->validate('videos/lecture-02.mp4', $query);
    }

    public function test_rejects_tampered_expiration_parameter(): void
    {
        $path = 'videos/lecture-01.mp4';
        $signedUrl = $this->signer->sign($path, expiration: 3600);

        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);
        $query['expires'] = (int) $query['expires'] + 10000; // Extend expiration illegally

        $this->expectException(InvalidSignedStreamUrlException::class);
        $this->signer->validate($path, $query);
    }

    public function test_ip_binding_anti_leech(): void
    {
        $ipSigner = $this->signer->withIpBinding(true);
        $path = 'audio/quran-01.mp3';

        // Sign for IP 192.168.1.50
        $signedUrl = $ipSigner->sign($path, expiration: 3600, extraParams: ['ip' => '192.168.1.50']);
        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

        // Same IP: Valid
        $this->assertTrue($ipSigner->validate($path, $query, clientIp: '192.168.1.50'));

        // Different IP: Forbidden (Anti-Leech)
        $this->expectException(InvalidSignedStreamUrlException::class);
        $ipSigner->validate($path, $query, clientIp: '10.0.0.1');
    }

    public function test_validates_directly_from_http_request(): void
    {
        $path = 'videos/intro.mp4';
        $signedUrl = $this->signer->sign($path, expiration: 1800);
        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

        $request = Request::create($signedUrl, 'GET', $query);

        $this->assertTrue($this->signer->validateRequest($request, $path));
    }

    public function test_exception_generates_http_403_response(): void
    {
        $e = new InvalidSignedStreamUrlException('Expired token');
        $response = $e->toResponse();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Access Denied', (string) $response->getContent());
    }
}

