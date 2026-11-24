<?php

declare(strict_types=1);

namespace EntityStreamer;

use EntityStreamer\Security\StreamUrlSigner;
use EntityStreamer\Storage\StreamableFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Central manager coordinating media streaming, configuration, and URL signing.
 */
class StreamerManager
{
    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Start a new streaming builder for a media path.
     */
    public function stream(string $path): StreamerBuilder
    {
        return (new StreamerBuilder($this->config))->path($path);
    }

    /**
     * Specify target storage disk.
     */
    public function disk(?string $disk): StreamerBuilder
    {
        return (new StreamerBuilder($this->config))->disk($disk);
    }

    public function withChunkSize(int $bytes): StreamerBuilder
    {
        return (new StreamerBuilder($this->config))->withChunkSize($bytes);
    }

    public function withThrottle(?int $kbps): StreamerBuilder
    {
        return (new StreamerBuilder($this->config))->withThrottle($kbps);
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    public function withFallbacks(array $prefixes): StreamerBuilder
    {
        return (new StreamerBuilder($this->config))->withFallbacks($prefixes);
    }

    public function asInline(?string $filename = null): StreamerBuilder
    {
        return (new StreamerBuilder($this->config))->asInline($filename);
    }

    public function asAttachment(?string $filename = null): StreamerBuilder
    {
        return (new StreamerBuilder($this->config))->asAttachment($filename);
    }

    /**
     * Generate an HMAC-SHA256 signed streaming URL.
     *
     * @param  string  $path  Media path or route
     * @param  mixed  $expiration  Expiration timestamp or relative seconds
     * @param  array<string, mixed>  $parameters  Extra query parameters
     * @param  bool  $bindToIp  Restrict URL to recipient's IP address
     */
    public function sign(
        string $path,
        mixed $expiration = null,
        array $parameters = [],
        bool $bindToIp = false
    ): string {
        $ttl = $expiration ?? ($this->config['security']['signature_ttl'] ?? 3600);
        $bind = $bindToIp || (bool) ($this->config['security']['bind_to_ip'] ?? false);

        $signer = new StreamUrlSigner(bindToIp: $bind);

        return $signer->sign($path, $ttl, $parameters);
    }

    /**
     * Validate an incoming HTTP request containing a signed streaming URL.
     */
    public function validateSignature(Request $request, string $path): bool
    {
        $bind = (bool) ($this->config['security']['bind_to_ip'] ?? false);
        $signer = new StreamUrlSigner(bindToIp: $bind);

        return $signer->isValidRequest($request, $path);
    }

    /**
     * Directly resolve a path to its StreamableFile.
     */
    public function resolveFile(string $path, ?string $disk = null): StreamableFile
    {
        return $this->stream($path)->disk($disk)->resolveFile();
    }
}
