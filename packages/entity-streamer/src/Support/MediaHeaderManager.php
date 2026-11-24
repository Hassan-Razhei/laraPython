<?php

declare(strict_types=1);

namespace EntityStreamer\Support;

use EntityStreamer\Storage\StreamableFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages HTTP Cache Control, Conditional Requests (304 Not Modified),
 * and Content-Disposition headers for streaming media assets.
 */
class MediaHeaderManager
{
    protected MimeDetector $mimeDetector;
    protected string $cacheControl = 'public, max-age=86400, no-transform';
    protected bool $weakEtag = true;

    public function __construct(?MimeDetector $mimeDetector = null)
    {
        $this->mimeDetector = $mimeDetector ?? new MimeDetector();
    }

    public function withCacheControl(string $cacheControl): self
    {
        $clone = clone $this;
        $clone->cacheControl = $cacheControl;
        return $clone;
    }

    public function withWeakEtag(bool $weak): self
    {
        $clone = clone $this;
        $clone->weakEtag = $weak;
        return $clone;
    }

    public function getMimeDetector(): MimeDetector
    {
        return $this->mimeDetector;
    }

    /**
     * Generate an HTTP ETag matching the file's size and last modified timestamp.
     */
    public function generateEtag(StreamableFile $file): string
    {
        $tag = sprintf('%x-%x', $file->size, $file->lastModified);
        return $this->weakEtag ? "W/\"{$tag}\"" : "\"{$tag}\"";
    }

    /**
     * Format a UNIX timestamp into RFC 7231 HTTP Date.
     */
    public function formatLastModified(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
    }

    /**
     * Format a safe, RFC 6266 compliant Content-Disposition header.
     *
     * @param string $filename Base filename to display to user
     * @param bool $inline True for browser streaming player, false for attachment download
     */
    public function formatContentDisposition(string $filename, bool $inline = true): string
    {
        $disposition = $inline ? 'inline' : 'attachment';
        $basename = basename($filename);

        // ASCII fallback
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $basename) ?: 'media';
        // UTF-8 encoded filename per RFC 5987 / RFC 6266
        $utf8Name = rawurlencode($basename);

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $disposition, $asciiName, $utf8Name);
    }

    /**
     * Inspect incoming request for conditional validation headers (If-None-Match, If-Modified-Since).
     * If valid, returns an HTTP 304 Not Modified Response, saving bandwidth.
     */
    public function checkNotModified(Request $request, StreamableFile $file): ?Response
    {
        $etag = $this->generateEtag($file);
        $lastModified = $file->lastModified;

        // 1. Check If-None-Match (ETag)
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null) {
            $clientEtags = array_map('trim', explode(',', $ifNoneMatch));

            foreach ($clientEtags as $clientTag) {
                // Strip weak prefix for comparison
                $normalizedClient = ltrim($clientTag, 'W/');
                $normalizedServer = ltrim($etag, 'W/');

                if ($clientTag === '*' || $normalizedClient === $normalizedServer) {
                    return $this->createNotModifiedResponse($file);
                }
            }
        }

        // 2. Check If-Modified-Since (Timestamp)
        $ifModifiedSince = $request->headers->get('If-Modified-Since');
        if ($ifModifiedSince !== null && $lastModified > 0) {
            $sinceTimestamp = strtotime($ifModifiedSince);
            if ($sinceTimestamp !== false && $lastModified <= $sinceTimestamp) {
                return $this->createNotModifiedResponse($file);
            }
        }

        return null;
    }

    /**
     * Build the standard headers for media streaming.
     *
     * @param StreamableFile $file Target media asset
     * @param bool $inline True for player streaming, false for file download
     * @param array<string, string> $overrides Custom headers to merge
     * @return array<string, string>
     */
    public function buildHeaders(
        StreamableFile $file,
        bool $inline = true,
        array $overrides = []
    ): array {
        $mime = $file->mimeType ?: $this->mimeDetector->detect($file->path);

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => $this->formatContentDisposition($file->path, $inline),
            'Accept-Ranges' => 'bytes',
            'ETag' => $this->generateEtag($file),
            'Last-Modified' => $this->formatLastModified($file->lastModified),
            'Cache-Control' => $this->cacheControl,
        ];

        return array_merge($headers, $overrides);
    }

    /**
     * Generate an HTTP 304 Not Modified response.
     */
    protected function createNotModifiedResponse(StreamableFile $file): Response
    {
        return new Response(
            content: '',
            status: Response::HTTP_NOT_MODIFIED,
            headers: [
                'ETag' => $this->generateEtag($file),
                'Last-Modified' => $this->formatLastModified($file->lastModified),
                'Cache-Control' => $this->cacheControl,
                'Accept-Ranges' => 'bytes',
            ]
        );
    }
}

