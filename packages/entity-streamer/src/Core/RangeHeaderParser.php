<?php

declare(strict_types=1);

namespace EntityStreamer\Core;

use EntityStreamer\Exceptions\InvalidByteRangeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * RFC 7233 Range Header Parser.
 *
 * Implements strict HTTP Byte Range parsing, validating closed, open-ended,
 * and suffix byte ranges against total content size.
 */
class RangeHeaderParser
{
    /**
     * Parse an HTTP Range header string against a known total file size.
     *
     * @param  string|null  $rangeHeader  Raw Range header, e.g. "bytes=0-1024"
     * @param  int  $totalSize  Total file size in bytes
     * @return ByteRange Parsed immutable ByteRange instance
     *
     * @throws InvalidByteRangeException When range is unsatisfiable (HTTP 416)
     */
    public function parse(?string $rangeHeader, int $totalSize): ByteRange
    {
        if ($totalSize < 0) {
            throw new InvalidByteRangeException("Total file size cannot be negative: {$totalSize}", $totalSize);
        }

        // When total size is 0, any Range request is unsatisfiable per RFC 7233
        if ($totalSize === 0) {
            if ($rangeHeader !== null && trim($rangeHeader) !== '') {
                throw new InvalidByteRangeException(
                    'Cannot satisfy byte range on an empty file.',
                    $totalSize,
                    $rangeHeader
                );
            }

            return ByteRange::full(0);
        }

        // If no Range header is supplied, serve full representation (HTTP 200)
        if ($rangeHeader === null || trim($rangeHeader) === '') {
            return ByteRange::full($totalSize);
        }

        $trimmed = trim($rangeHeader);

        // RFC 7233: If the range unit is not "bytes", ignore and serve 200 OK
        if (! preg_match('/^bytes\s*=\s*(.+)$/i', $trimmed, $matches)) {
            return ByteRange::full($totalSize);
        }

        $rangeSpecs = $matches[1];

        // If multiple ranges are requested, take the primary (first) range spec
        if (str_contains($rangeSpecs, ',')) {
            $parts = explode(',', $rangeSpecs);
            $rangeSpec = trim($parts[0]);
        } else {
            $rangeSpec = trim($rangeSpecs);
        }

        // Match the single range spec: "start-end", "start-", or "-suffix" (allowing optional whitespace around hyphen)
        if (! preg_match('/^(\d*)\s*-\s*(\d*)$/', $rangeSpec, $parts)) {
            // Unrecognized range format: ignore and return full content (200 OK)
            return ByteRange::full($totalSize);
        }

        $rawStart = $parts[1];
        $rawEnd = $parts[2];

        // Case 1: Suffix Range "-suffix" (e.g. "-500" -> last 500 bytes)
        if ($rawStart === '' && $rawEnd !== '') {
            $suffixLength = (int) $rawEnd;

            // RFC 7233: Suffix length must be positive
            if ($suffixLength <= 0) {
                throw new InvalidByteRangeException(
                    "Invalid suffix range: [{$rangeSpec}]. Suffix length must be greater than zero.",
                    $totalSize,
                    $rangeHeader
                );
            }

            if ($suffixLength >= $totalSize) {
                // Suffix is larger than file: return entire file
                return ByteRange::partial(0, $totalSize - 1, $totalSize);
            }

            $start = $totalSize - $suffixLength;
            $end = $totalSize - 1;

            return ByteRange::partial($start, $end, $totalSize);
        }

        // Case 2: Open Range "start-" (e.g. "1024-" -> from 1024 to end of file)
        if ($rawStart !== '' && $rawEnd === '') {
            $start = (int) $rawStart;

            // RFC 7233: If start is beyond end of file, 416 Range Not Satisfiable
            if ($start >= $totalSize) {
                throw new InvalidByteRangeException(
                    "Range start {$start} is outside the total file size {$totalSize}.",
                    $totalSize,
                    $rangeHeader
                );
            }

            $end = $totalSize - 1;

            return ByteRange::partial($start, $end, $totalSize);
        }

        // Case 3: Closed Range "start-end" (e.g. "0-1024")
        if ($rawStart !== '' && $rawEnd !== '') {
            $start = (int) $rawStart;
            $end = (int) $rawEnd;

            // RFC 7233: start <= end
            if ($start > $end) {
                throw new InvalidByteRangeException(
                    "Range start {$start} cannot be greater than end {$end}.",
                    $totalSize,
                    $rangeHeader
                );
            }

            // RFC 7233: start must be less than total size
            if ($start >= $totalSize) {
                throw new InvalidByteRangeException(
                    "Range start {$start} is outside the total file size {$totalSize}.",
                    $totalSize,
                    $rangeHeader
                );
            }

            // RFC 7233 Section 2.1: If end is >= totalSize, clamp end to totalSize - 1
            if ($end >= $totalSize) {
                $end = $totalSize - 1;
            }

            return ByteRange::partial($start, $end, $totalSize);
        }

        // Both parts are empty (e.g. "bytes=-"): ignore and serve 200 OK
        return ByteRange::full($totalSize);
    }

    /**
     * Convenience method to parse Range header from an incoming HTTP Request.
     */
    public function parseRequest(Request $request, int $totalSize): ByteRange
    {
        $header = $request->headers->get('Range');

        return $this->parse($header, $totalSize);
    }
}
