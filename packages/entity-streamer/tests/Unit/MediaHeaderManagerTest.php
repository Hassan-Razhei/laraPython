<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Storage\StreamableFile;
use EntityStreamer\Support\MediaHeaderManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class MediaHeaderManagerTest extends TestCase
{
    private MediaHeaderManager $headerManager;
    private StreamableFile $sampleFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->headerManager = new MediaHeaderManager();
        $this->sampleFile = new StreamableFile(
            path: 'videos/درس_المخطوطات.mp4',
            size: 5242880,
            lastModified: 1700000000,
            disk: 'public',
            mimeType: 'video/mp4'
        );
    }

    public function test_generates_etag_correctly(): void
    {
        $weakEtag = $this->headerManager->generateEtag($this->sampleFile);
        $this->assertStringStartsWith('W/"', $weakEtag);
        $this->assertStringEndsWith('"', $weakEtag);

        $strongManager = (new MediaHeaderManager())->withWeakEtag(false);
        $strongEtag = $strongManager->generateEtag($this->sampleFile);
        $this->assertStringStartsNotWith('W/', $strongEtag);
        $this->assertSame('"' . sprintf('%x-%x', 5242880, 1700000000) . '"', $strongEtag);
    }

    public function test_formats_content_disposition_with_utf8_rfc5987(): void
    {
        // Testing Arabic filename
        $disposition = $this->headerManager->formatContentDisposition('شرح_المنظومة.mp4', inline: true);

        $this->assertStringStartsWith('inline;', $disposition);
        $this->assertStringContainsString('filename="____.mp4"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''", $disposition);
        $this->assertStringContainsString(rawurlencode('شرح_المنظومة.mp4'), $disposition);

        // Attachment mode
        $attachment = $this->headerManager->formatContentDisposition('audio.mp3', inline: false);
        $this->assertStringStartsWith('attachment;', $attachment);
    }

    public function test_conditional_request_returns_304_on_matching_if_none_match(): void
    {
        $etag = $this->headerManager->generateEtag($this->sampleFile);

        $request = Request::create('/stream/video.mp4', 'GET', [], [], [], [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);

        $response = $this->headerManager->checkNotModified($request, $this->sampleFile);

        $this->assertNotNull($response);
        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame($etag, $response->headers->get('ETag'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    public function test_conditional_request_returns_304_on_valid_if_modified_since(): void
    {
        $rfcDate = gmdate('D, d M Y H:i:s \G\M\T', 1700000500); // 500s later than file

        $request = Request::create('/stream/video.mp4', 'GET', [], [], [], [
            'HTTP_IF_MODIFIED_SINCE' => $rfcDate,
        ]);

        $response = $this->headerManager->checkNotModified($request, $this->sampleFile);

        $this->assertNotNull($response);
        $this->assertSame(304, $response->getStatusCode());
    }

    public function test_conditional_request_returns_null_when_file_is_newer(): void
    {
        $rfcDate = gmdate('D, d M Y H:i:s \G\M\T', 1600000000); // Old date

        $request = Request::create('/stream/video.mp4', 'GET', [], [], [], [
            'HTTP_IF_MODIFIED_SINCE' => $rfcDate,
        ]);

        $response = $this->headerManager->checkNotModified($request, $this->sampleFile);
        $this->assertNull($response);
    }

    public function test_builds_complete_streaming_headers(): void
    {
        $headers = $this->headerManager->buildHeaders($this->sampleFile, inline: true);

        $this->assertSame('video/mp4', $headers['Content-Type']);
        $this->assertSame('bytes', $headers['Accept-Ranges']);
        $this->assertArrayHasKey('ETag', $headers);
        $this->assertArrayHasKey('Last-Modified', $headers);
        $this->assertSame('public, max-age=86400, no-transform', $headers['Cache-Control']);
        $this->assertStringStartsWith('inline;', $headers['Content-Disposition']);
    }
}

