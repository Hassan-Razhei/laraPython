<?php

declare(strict_types=1);

namespace EntityStreamer\Core;

use EntityStreamer\Exceptions\InvalidByteRangeException;

/**
 * Immutable Value Object representing an RFC 7233 Byte Range.
 */
readonly class ByteRange
{
    public int $start;
    public int $end;
    public int $length;
    public int $total;
    public bool $isPartial;

    /**
     * @param int $start Starting byte offset (0-indexed, inclusive)
     * @param int $end Ending byte offset (0-indexed, inclusive)
     * @param int $total Total file size in bytes
     * @param bool $isPartial True if partial content (206), false if complete file (200)
     */
    public function __construct(int $start, int $end, int $total, bool $isPartial = true)
    {
        if ($total < 0) {
            throw new InvalidByteRangeException("Total file size cannot be negative: {$total}", $total);
        }

        // Special case: empty file ($total == 0)
        if ($total === 0) {
            $this->start = 0;
            $this->end = 0;
            $this->total = 0;
            $this->length = 0;
            $this->isPartial = false;
            return;
        }

        // Validate bounds: 0 <= start <= end < total
        if ($start < 0 || $start > $end || $end >= $total) {
            throw new InvalidByteRangeException(
                "Invalid byte range [{$start}-{$end}] for total size {$total}.",
                $total
            );
        }

        $this->start = $start;
        $this->end = $end;
        $this->total = $total;
        $this->length = $end - $start + 1;
        $this->isPartial = $isPartial;
    }

    /**
     * Factory for full file representation (HTTP 200 OK).
     */
    public static function full(int $total): self
    {
        $end = $total > 0 ? $total - 1 : 0;
        return new self(start: 0, end: $end, total: $total, isPartial: false);
    }

    /**
     * Factory for partial byte range (HTTP 206 Partial Content).
     */
    public static function partial(int $start, int $end, int $total): self
    {
        return new self(start: $start, end: $end, total: $total, isPartial: true);
    }

    /**
     * Get the RFC 7233 Content-Range header string.
     * Example: "bytes 100-200/5000"
     */
    public function toContentRangeHeader(): string
    {
        if ($this->total === 0) {
            return 'bytes */0';
        }

        return sprintf('bytes %d-%d/%d', $this->start, $this->end, $this->total);
    }

    /**
     * Get the Content-Length in bytes for the response.
     */
    public function toContentLength(): int
    {
        return $this->length;
    }

    /**
     * Determine recommended HTTP status code: 206 for partial content, 200 for full.
     */
    public function getStatusCode(): int
    {
        return $this->isPartial ? 206 : 200;
    }

    /**
     * Determine if this range encompasses the entire file content.
     */
    public function isFullFile(): bool
    {
        if (!$this->isPartial) {
            return true;
        }

        if ($this->total === 0) {
            return true;
        }

        return $this->start === 0 && $this->end === ($this->total - 1);
    }

    /**
     * Array representation for debugging, logging, and metrics.
     *
     * @return array{start: int, end: int, length: int, total: int, is_partial: bool, status_code: int, content_range: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
            'length' => $this->length,
            'total' => $this->total,
            'is_partial' => $this->isPartial,
            'status_code' => $this->getStatusCode(),
            'content_range' => $this->toContentRangeHeader(),
        ];
    }
}

