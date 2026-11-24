<?php

declare(strict_types=1);

namespace EntityStreamer\Security;

use DateInterval;
use DateTimeInterface;
use EntityStreamer\Exceptions\InvalidSignedStreamUrlException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Generates and validates time-limited HMAC-SHA256 signed streaming URLs,
 * with optional IP-binding (Anti-Leech / Hotlink protection).
 */
class StreamUrlSigner
{
    protected string $secretKey;

    protected bool $bindToIp = false;

    public function __construct(?string $secretKey = null, bool $bindToIp = false)
    {
        $this->secretKey = $secretKey ?: $this->resolveDefaultKey();
        $this->bindToIp = $bindToIp;
    }

    public function withSecretKey(string $key): self
    {
        $clone = clone $this;
        $clone->secretKey = $key;

        return $clone;
    }

    public function withIpBinding(bool $bind): self
    {
        $clone = clone $this;
        $clone->bindToIp = $bind;

        return $clone;
    }

    /**
     * Sign a media path or URL with an expiration and optional context parameters.
     *
     * @param  string  $path  Target relative path or URL
     * @param  DateTimeInterface|DateInterval|int  $expiration  Expiration timestamp or interval
     * @param  array<string, mixed>  $extraParams  Context parameters (e.g. 'ip' => '1.2.3.4')
     * @return string Signed query string or complete signed URL
     */
    public function sign(string $path, mixed $expiration, array $extraParams = []): string
    {
        $expires = $this->resolveExpirationTimestamp($expiration);

        $params = array_merge($extraParams, [
            'expires' => $expires,
        ]);

        if ($this->bindToIp && ! isset($params['ip']) && isset($_SERVER['REMOTE_ADDR'])) {
            $params['ip'] = (string) $_SERVER['REMOTE_ADDR'];
        }

        // Sort parameters to ensure deterministic signature
        ksort($params);

        $signature = $this->computeSignature($path, $params);
        $params['signature'] = $signature;

        $queryString = http_build_query($params);

        if (str_contains($path, '?')) {
            return $path.'&'.$queryString;
        }

        return $path.'?'.$queryString;
    }

    /**
     * Validate an incoming HTTP request for a signed media streaming URL.
     *
     * @throws InvalidSignedStreamUrlException
     */
    public function validateRequest(Request $request, string $path): bool
    {
        $queryParams = $request->query->all();
        $clientIp = $this->bindToIp ? $request->getClientIp() : null;

        return $this->validate($path, $queryParams, $clientIp);
    }

    /**
     * Check whether a signed URL request is valid without throwing an exception.
     */
    public function isValidRequest(Request $request, string $path): bool
    {
        try {
            return $this->validateRequest($request, $path);
        } catch (InvalidSignedStreamUrlException) {
            return false;
        }
    }

    /**
     * Check whether query parameters and signature are valid without throwing an exception.
     */
    public function isValid(string $path, array $queryParams, ?string $clientIp = null): bool
    {
        try {
            return $this->validate($path, $queryParams, $clientIp);
        } catch (InvalidSignedStreamUrlException) {
            return false;
        }
    }

    /**
     * Validate query parameters and signature against path and client IP.
     *
     * @param  string  $path  Media path
     * @param  array<string, mixed>  $queryParams  Request query parameters
     * @param  string|null  $clientIp  Client IP if IP-binding is enabled
     *
     * @throws InvalidSignedStreamUrlException
     */
    public function validate(string $path, array $queryParams, ?string $clientIp = null): bool
    {
        if (! isset($queryParams['signature']) || ! isset($queryParams['expires'])) {
            throw new InvalidSignedStreamUrlException('Missing signature or expiration parameters.');
        }

        $providedSignature = (string) $queryParams['signature'];
        $expires = (int) $queryParams['expires'];

        // 1. Verify expiration
        if (time() > $expires) {
            throw new InvalidSignedStreamUrlException('The streaming link has expired.');
        }

        // 2. Verify IP binding if configured
        if ($this->bindToIp) {
            $signedIp = $queryParams['ip'] ?? null;
            if ($clientIp !== null && $signedIp !== null && $signedIp !== $clientIp) {
                throw new InvalidSignedStreamUrlException('Client IP does not match the signed URL recipient.');
            }
        }

        // 3. Compute expected signature (excluding signature param itself)
        $cleanParams = $queryParams;
        unset($cleanParams['signature']);
        ksort($cleanParams);

        $expectedSignature = $this->computeSignature($path, $cleanParams);

        // Constant-time string comparison to prevent timing attacks
        if (! hash_equals($expectedSignature, $providedSignature)) {
            throw new InvalidSignedStreamUrlException('Signature verification failed.');
        }

        return true;
    }

    /**
     * Compute HMAC-SHA256 signature for path and sorted query parameters.
     *
     * @param  array<string, mixed>  $params
     */
    public function computeSignature(string $path, array $params): string
    {
        ksort($params);
        $payload = ltrim($path, '/').'::'.http_build_query($params);

        return hash_hmac('sha256', $payload, $this->secretKey);
    }

    protected function resolveExpirationTimestamp(mixed $expiration): int
    {
        if ($expiration instanceof DateTimeInterface) {
            return $expiration->getTimestamp();
        }

        if ($expiration instanceof DateInterval) {
            return (new \DateTimeImmutable)->add($expiration)->getTimestamp();
        }

        if (is_numeric($expiration)) {
            $val = (int) $expiration;
            // If small number, treat as seconds from now (e.g. 3600 -> time() + 3600)
            if ($val < 1_000_000_000) {
                return time() + $val;
            }

            return $val;
        }

        return time() + 3600; // Default: 1 hour
    }

    protected function resolveDefaultKey(): string
    {
        if (function_exists('app') && function_exists('config')) {
            try {
                if (app()->bound('config')) {
                    $appKey = (string) config('app.key');
                    if ($appKey !== '') {
                        return $appKey;
                    }
                }
            } catch (\Throwable) {
                // Container not yet booted
            }
        }

        return 'entity_streamer_default_secret_fallback_key';
    }
}
