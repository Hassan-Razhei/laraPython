<?php

declare(strict_types=1);

namespace EntityStreamer;

use EntityStreamer\Core\BinaryStreamer;
use EntityStreamer\Core\RangeHeaderParser;
use EntityStreamer\Security\PathSanitizer;
use EntityStreamer\Storage\StreamableFile;
use EntityStreamer\Storage\StreamableStorageAdapter;
use EntityStreamer\Support\MediaHeaderManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fluent builder for configuring and executing media stream requests.
 */
class StreamerBuilder
{
    protected string $path = '';

    protected ?string $disk = null;

    /** @var array<int, string> */
    protected array $fallbackPrefixes = [];

    protected int $chunkSize = BinaryStreamer::DEFAULT_CHUNK_SIZE;

    protected ?int $throttleKbps = null;

    protected bool $inline = true;

    protected ?string $customFilename = null;

    /** @var array<string, string> */
    protected array $headers = [];

    protected ?string $cacheControl = null;

    protected ?bool $weakEtag = null;

    /**
     * @param  array<string, mixed>  $defaults
     */
    public function __construct(array $defaults = [])
    {
        if (isset($defaults['default_disk'])) {
            $this->disk = $defaults['default_disk'];
        }
        if (isset($defaults['chunk_size'])) {
            $this->chunkSize = (int) $defaults['chunk_size'];
        }
        if (isset($defaults['throttle_kbps'])) {
            $this->throttleKbps = (int) $defaults['throttle_kbps'];
        }
        if (isset($defaults['fallback_prefixes']) && is_array($defaults['fallback_prefixes'])) {
            $this->fallbackPrefixes = $defaults['fallback_prefixes'];
        }
        if (isset($defaults['cache']['control'])) {
            $this->cacheControl = (string) $defaults['cache']['control'];
        }
        if (isset($defaults['cache']['weak_etag'])) {
            $this->weakEtag = (bool) $defaults['cache']['weak_etag'];
        }
    }

    public function path(string $path): self
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    public function disk(?string $disk): self
    {
        $clone = clone $this;
        $clone->disk = $disk;

        return $clone;
    }

    public function withChunkSize(int $bytes): self
    {
        $clone = clone $this;
        $clone->chunkSize = max(BinaryStreamer::MIN_CHUNK_SIZE, $bytes);

        return $clone;
    }

    public function withThrottle(?int $kbps): self
    {
        $clone = clone $this;
        $clone->throttleKbps = ($kbps !== null && $kbps > 0) ? $kbps : null;

        return $clone;
    }

    /**
     * @param  array<int, string>  $prefixes
     */
    public function withFallbacks(array $prefixes): self
    {
        $clone = clone $this;
        $clone->fallbackPrefixes = $prefixes;

        return $clone;
    }

    public function addFallback(string $prefix): self
    {
        $clone = clone $this;
        $clone->fallbackPrefixes[] = $prefix;

        return $clone;
    }

    public function asInline(?string $filename = null): self
    {
        $clone = clone $this;
        $clone->inline = true;
        if ($filename !== null) {
            $clone->customFilename = $filename;
        }

        return $clone;
    }

    public function asAttachment(?string $filename = null): self
    {
        $clone = clone $this;
        $clone->inline = false;
        if ($filename !== null) {
            $clone->customFilename = $filename;
        }

        return $clone;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($clone->headers, $headers);

        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /**
     * Resolve the underlying StreamableFile from current builder state.
     */
    public function resolveFile(): StreamableFile
    {
        $sanitizer = new PathSanitizer;
        $safePath = $sanitizer->sanitize($this->path);

        $adapter = (new StreamableStorageAdapter(disk: $this->disk))
            ->withFallbackPrefixes($this->fallbackPrefixes);

        return $adapter->resolve($safePath);
    }

    /**
     * Build and return an RFC 7233 compliant HTTP response.
     */
    public function toResponse(?Request $request = null): Response
    {
        if ($request === null) {
            $request = function_exists('request') ? request() : Request::createFromGlobals();
        }

        $file = $this->resolveFile();

        $headerManager = new MediaHeaderManager;
        if ($this->cacheControl !== null) {
            $headerManager = $headerManager->withCacheControl($this->cacheControl);
        }
        if ($this->weakEtag !== null) {
            $headerManager = $headerManager->withWeakEtag($this->weakEtag);
        }

        // 1. Check HTTP 304 Not Modified
        $notModified = $headerManager->checkNotModified($request, $file);
        if ($notModified !== null) {
            return $notModified;
        }

        // 2. Parse RFC 7233 Range header
        $parser = new RangeHeaderParser;
        $range = $parser->parseRequest($request, $file->size);

        // 3. Build HTTP headers
        $customFilename = $this->customFilename ?: basename($file->path);
        $headers = $this->headers;
        $headers['Content-Disposition'] = $headerManager->formatContentDisposition($customFilename, $this->inline);

        $streamHeaders = $headerManager->buildHeaders($file, $this->inline, $headers);

        // 4. Stream response
        $streamer = (new BinaryStreamer(chunkSize: $this->chunkSize))
            ->withThrottle($this->throttleKbps);

        $stream = $file->openStream();

        return $streamer->toStreamedResponse($stream, $range, $streamHeaders);
    }
}
