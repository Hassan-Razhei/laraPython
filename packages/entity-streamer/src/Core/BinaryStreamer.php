<?php

declare(strict_types=1);

namespace EntityStreamer\Core;

use EntityStreamer\Exceptions\StreamResourceException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * High-performance, memory-safe binary stream kernel.
 *
 * Implements chunked streaming directly to HTTP client with client-abort detection,
 * zero memory ballooning via output buffer management, and optional bandwidth throttling.
 */
class BinaryStreamer
{
    /** Default chunk buffer size: 64 KB */
    public const DEFAULT_CHUNK_SIZE = 64 * 1024;

    /** Minimum permissible chunk size: 1 KB */
    public const MIN_CHUNK_SIZE = 1024;

    protected int $chunkSize;
    protected ?int $throttleKbps = null;
    protected bool $cleanBuffers = true;

    public function __construct(int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        $this->chunkSize = max(self::MIN_CHUNK_SIZE, $chunkSize);
    }

    /**
     * Set chunk buffer size in bytes.
     */
    public function withChunkSize(int $bytes): self
    {
        $clone = clone $this;
        $clone->chunkSize = max(self::MIN_CHUNK_SIZE, $bytes);
        return $clone;
    }

    /**
     * Configure bandwidth throttling in Kilobytes per second (KB/s).
     * Pass null or 0 to disable rate shaping.
     */
    public function withThrottle(?int $kbps): self
    {
        $clone = clone $this;
        $clone->throttleKbps = ($kbps !== null && $kbps > 0) ? $kbps : null;
        return $clone;
    }

    /**
     * Disable output buffer clearing (useful in testing or embedded pipelines).
     */
    public function withoutBufferCleaning(): self
    {
        $clone = clone $this;
        $clone->cleanBuffers = false;
        return $clone;
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    public function getThrottle(): ?int
    {
        return $this->throttleKbps;
    }

    /**
     * Stream binary data matching the ByteRange to an output target.
     *
     * @param resource|string $source Stream resource or filesystem path
     * @param ByteRange $range Range boundaries to stream
     * @param resource|null $outputTarget Optional destination stream (defaults to echo/php://output)
     * @return int Total bytes successfully streamed
     *
     * @throws StreamResourceException
     */
    public function stream(mixed $source, ByteRange $range, mixed $outputTarget = null): int
    {
        $handle = null;
        $openedLocally = false;

        if (is_string($source)) {
            if (!file_exists($source) || !is_readable($source)) {
                throw StreamResourceException::unableToOpenFile($source, 'File does not exist or is not readable');
            }

            $handle = @fopen($source, 'rb');
            if ($handle === false) {
                throw StreamResourceException::unableToOpenFile($source, 'fopen returned false');
            }
            $openedLocally = true;
        } elseif (is_resource($source) && get_resource_type($source) === 'stream') {
            $handle = $source;
        } else {
            throw StreamResourceException::invalidResource();
        }

        try {
            // Empty file or empty range
            if ($range->length === 0 || $range->total === 0) {
                return 0;
            }

            // Seek directly to start offset
            if ($range->start > 0) {
                if (@fseek($handle, $range->start, SEEK_SET) !== 0) {
                    throw StreamResourceException::seekFailed($range->start);
                }
            }

            // Clean active output buffers to prevent memory bloating
            if ($this->cleanBuffers && $outputTarget === null) {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
            }

            $bytesSent = 0;
            $remaining = $range->length;
            $chunkSize = $this->chunkSize;

            while ($remaining > 0 && !feof($handle)) {
                // If client disconnected, stop immediately to save server IOPS and bandwidth
                if ($outputTarget === null && connection_aborted()) {
                    break;
                }

                $toRead = min($chunkSize, $remaining);
                $buffer = fread($handle, $toRead);

                if ($buffer === false || $buffer === '') {
                    break;
                }

                $readLength = strlen($buffer);

                if ($outputTarget !== null) {
                    fwrite($outputTarget, $buffer);
                } else {
                    echo $buffer;
                    if (function_exists('flush')) {
                        flush();
                    }
                }

                $bytesSent += $readLength;
                $remaining -= $readLength;

                // Bandwidth throttling / rate shaping
                if ($this->throttleKbps !== null && $this->throttleKbps > 0) {
                    $this->applyThrottling($readLength);
                }
            }

            return $bytesSent;
        } finally {
            if ($openedLocally && is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Create an RFC 7233 compliant Symfony/Laravel StreamedResponse.
     *
     * @param resource|string $source Media stream or file path
     * @param ByteRange $range Target byte range
     * @param array<string, string> $headers Additional HTTP response headers
     */
    public function toStreamedResponse(
        mixed $source,
        ByteRange $range,
        array $headers = []
    ): StreamedResponse {
        $statusCode = $range->getStatusCode();

        $defaultHeaders = [
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $range->toContentLength(),
        ];

        // RFC 7233: Content-Range is sent with 206 Partial Content
        if ($range->isPartial && $range->total > 0) {
            $defaultHeaders['Content-Range'] = $range->toContentRangeHeader();
        }

        $finalHeaders = array_merge($defaultHeaders, $headers);

        $callback = function () use ($source, $range): void {
            $this->stream($source, $range);
        };

        return new StreamedResponse(
            $callback,
            $statusCode,
            $finalHeaders
        );
    }

    /**
     * Pace execution to match target transmission rate in KB/s.
     */
    protected function applyThrottling(int $bytesTransferred): void
    {
        if ($this->throttleKbps === null || $this->throttleKbps <= 0) {
            return;
        }

        // Calculate theoretical transmission time in microseconds:
        // (bytes / 1024) = KB; KB / throttleKbps = seconds; seconds * 1,000,000 = microseconds
        $microSeconds = (int) ((($bytesTransferred / 1024) / $this->throttleKbps) * 1_000_000);

        if ($microSeconds > 0) {
            usleep($microSeconds);
        }
    }
}
