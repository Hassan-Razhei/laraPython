<?php

declare(strict_types=1);

namespace EntityStreamer\Traits;

use EntityStreamer\Core\BinaryStreamer;
use EntityStreamer\Core\RangeHeaderParser;
use EntityStreamer\Exceptions\StreamResourceException;
use EntityStreamer\Security\PathSanitizer;
use EntityStreamer\Security\StreamUrlSigner;
use EntityStreamer\Storage\StreamableFile;
use EntityStreamer\Storage\StreamableStorageAdapter;
use EntityStreamer\Support\MediaHeaderManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trait providing seamless RFC 7233 media streaming capabilities to Eloquent models.
 */
trait HasStreamableMedia
{
    /** Common column names where media file paths are typically stored */
    protected array $defaultMediaColumns = [
        'file_path',
        'video_path',
        'audio_path',
        'media_path',
        'storage_path',
        'path',
        'url',
    ];

    /**
     * Get the relative storage or filesystem path for the specified media type.
     */
    public function getStreamPath(string $mediaType = 'default'): string
    {
        // 1. Check if model defines a streamableMedia mapping array
        if (isset($this->streamableMedia) && is_array($this->streamableMedia)) {
            if (isset($this->streamableMedia[$mediaType])) {
                $column = $this->streamableMedia[$mediaType];
                $val = $this->resolveModelAttribute($column);
                if ($val !== null && $val !== '') {
                    return (string) $val;
                }
            }
        }

        // 2. Check if a dedicated property/attribute exists for the mediaType
        if ($mediaType !== 'default') {
            $typeColumn = "{$mediaType}_path";
            $val = $this->resolveModelAttribute($typeColumn);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }

            $typeFile = "{$mediaType}_file";
            $val = $this->resolveModelAttribute($typeFile);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }

        // 3. Fallback search among default media column candidates
        foreach ($this->defaultMediaColumns as $candidate) {
            $val = $this->resolveModelAttribute($candidate);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }

        throw new StreamResourceException(
            sprintf('No streamable media path found on model [%s] for type [%s].', static::class, $mediaType)
        );
    }

    /**
     * Get the storage disk name for the requested media type (e.g. 'public', 's3', null).
     */
    public function getStreamDisk(string $mediaType = 'default'): ?string
    {
        // 1. Multi-disk map
        if (isset($this->streamableDisks) && is_array($this->streamableDisks)) {
            if (isset($this->streamableDisks[$mediaType])) {
                return $this->streamableDisks[$mediaType];
            }
        }

        // 2. Model-level streamDisk property
        if (isset($this->streamDisk) && is_string($this->streamDisk)) {
            return $this->streamDisk;
        }

        // 3. Config default if available in booted Laravel container
        if (function_exists('app') && function_exists('config')) {
            try {
                if (app()->bound('config')) {
                    $default = config('streamer.default_disk');
                    if ($default !== null) {
                        return (string) $default;
                    }
                }
            } catch (\Throwable) {
                // Container not yet booted
            }
        }

        return null;
    }

    /**
     * Resolve this model's media into a stream-ready StreamableFile.
     */
    public function toStreamableFile(string $mediaType = 'default'): StreamableFile
    {
        $rawPath = $this->getStreamPath($mediaType);

        // Sanitize path to prevent any directory climbing attacks
        $sanitizer = new PathSanitizer();
        $safePath = $sanitizer->sanitize($rawPath);

        $disk = $this->getStreamDisk($mediaType);
        $adapter = new StreamableStorageAdapter(disk: $disk);

        // Include any model-defined fallback directories
        if (isset($this->streamableFallbacks) && is_array($this->streamableFallbacks)) {
            $adapter = $adapter->withFallbackPrefixes($this->streamableFallbacks);
        }

        return $adapter->resolve($safePath);
    }

    /**
     * Stream this model's media directly to an HTTP response matching RFC 7233.
     */
    public function streamResponse(
        Request $request,
        string $mediaType = 'default',
        bool $inline = true,
        int $chunkSize = BinaryStreamer::DEFAULT_CHUNK_SIZE,
        array $headers = []
    ): Response {
        $file = $this->toStreamableFile($mediaType);
        $headerManager = new MediaHeaderManager();

        // 1. Check HTTP 304 Not Modified conditional validation
        $notModified = $headerManager->checkNotModified($request, $file);
        if ($notModified !== null) {
            return $notModified;
        }

        // 2. Parse RFC 7233 Range header
        $parser = new RangeHeaderParser();
        $range = $parser->parseRequest($request, $file->size);

        // 3. Build RFC 7233 streaming headers
        $streamHeaders = $headerManager->buildHeaders($file, $inline, $headers);

        // 4. Stream binary data via memory-safe kernel
        $streamer = new BinaryStreamer(chunkSize: $chunkSize);
        $stream = $file->openStream();

        return $streamer->toStreamedResponse($stream, $range, $streamHeaders);
    }

    /**
     * Generate an HMAC-SHA256 signed streaming URL for this model's media.
     */
    public function getStreamUrl(
        mixed $expiration = 3600,
        string $mediaType = 'default',
        array $parameters = [],
        bool $bindToIp = false
    ): string {
        $rawPath = $this->getStreamPath($mediaType);
        $sanitizer = new PathSanitizer();
        $safePath = $sanitizer->sanitize($rawPath);

        $endpoint = '/stream/' . ltrim($safePath, '/');

        // Allow model to customize endpoint if desired
        if (method_exists($this, 'getStreamEndpoint')) {
            $endpoint = $this->getStreamEndpoint($mediaType);
        }

        $signer = new StreamUrlSigner(bindToIp: $bindToIp);
        $extra = array_merge(['media_type' => $mediaType], $parameters);

        return $signer->sign($endpoint, $expiration, $extra);
    }

    /**
     * Safely resolve attribute value whether accessed via Eloquent getAttribute, property, or array.
     */
    protected function resolveModelAttribute(string $key): mixed
    {
        if (method_exists($this, 'getAttribute')) {
            $val = $this->getAttribute($key);
            if ($val !== null) {
                return $val;
            }
        }

        if (isset($this->{$key})) {
            return $this->{$key};
        }

        if (isset($this->attributes) && is_array($this->attributes) && isset($this->attributes[$key])) {
            return $this->attributes[$key];
        }

        return null;
    }
}
