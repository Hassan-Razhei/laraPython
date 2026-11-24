<?php

declare(strict_types=1);

namespace EntityStreamer\Contracts;

use EntityStreamer\Core\BinaryStreamer;
use EntityStreamer\Storage\StreamableFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface CanStreamMedia
{
    /**
     * Get the relative storage or file path for the requested media type.
     */
    public function getStreamPath(string $mediaType = 'default'): string;

    /**
     * Get the storage disk name for the requested media type (e.g. 'public', 's3', null).
     */
    public function getStreamDisk(string $mediaType = 'default'): ?string;

    /**
     * Resolve this model's media into a stream-ready StreamableFile.
     */
    public function toStreamableFile(string $mediaType = 'default'): StreamableFile;

    /**
     * Stream this model's media directly to an HTTP response matching RFC 7233.
     *
     * @param Request $request Incoming HTTP request containing optional Range header
     * @param string $mediaType Media channel identifier ('default', 'video', 'audio', etc.)
     * @param bool $inline True for inline playback, false for attachment download
     * @param int $chunkSize Buffer size in bytes
     * @param array<string, string> $headers Additional HTTP response headers
     */
    public function streamResponse(
        Request $request,
        string $mediaType = 'default',
        bool $inline = true,
        int $chunkSize = BinaryStreamer::DEFAULT_CHUNK_SIZE,
        array $headers = []
    ): Response;

    /**
     * Generate an HMAC-SHA256 signed streaming URL for this model's media.
     *
     * @param mixed $expiration Expiration timestamp or relative seconds
     * @param string $mediaType Media channel identifier
     * @param array<string, mixed> $parameters Extra query parameters
     * @param bool $bindToIp Restrict playback URL to client's IP address
     */
    public function getStreamUrl(
        mixed $expiration = 3600,
        string $mediaType = 'default',
        array $parameters = [],
        bool $bindToIp = false
    ): string;
}

