<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Core\BinaryStreamer;
use EntityStreamer\Core\ByteRange;
use EntityStreamer\Exceptions\StreamResourceException;
use PHPUnit\Framework\TestCase;

class BinaryStreamerTest extends TestCase
{
    private string $tempFilePath;
    private string $sampleContent;

    protected function setUp(): void
    {
        parent::setUp();

        // 100 KB sample text content
        $this->sampleContent = str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@', 1600);
        $this->tempFilePath = tempnam(sys_get_temp_dir(), 'stream_test_');
        file_put_contents($this->tempFilePath, $this->sampleContent);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFilePath)) {
            unlink($this->tempFilePath);
        }
        parent::tearDown();
    }

    public function test_can_stream_exact_byte_range_from_file_path(): void
    {
        $streamer = new BinaryStreamer(chunkSize: 4096);
        $range = ByteRange::partial(start: 10, end: 35, total: strlen($this->sampleContent));

        $outputStream = fopen('php://memory', 'w+b');
        $bytesSent = $streamer->stream($this->tempFilePath, $range, $outputStream);

        $this->assertSame(26, $bytesSent);

        rewind($outputStream);
        $streamedData = stream_get_contents($outputStream);
        fclose($outputStream);

        $expectedData = substr($this->sampleContent, 10, 26);
        $this->assertSame($expectedData, $streamedData);
    }

    public function test_can_stream_from_existing_stream_resource(): void
    {
        $streamer = (new BinaryStreamer(chunkSize: 1024))->withoutBufferCleaning();
        $source = fopen($this->tempFilePath, 'rb');
        $range = ByteRange::partial(start: 100, end: 199, total: strlen($this->sampleContent));

        $outputStream = fopen('php://memory', 'w+b');
        $bytesSent = $streamer->stream($source, $range, $outputStream);

        $this->assertSame(100, $bytesSent);

        rewind($outputStream);
        $streamedData = stream_get_contents($outputStream);
        fclose($outputStream);
        fclose($source);

        $this->assertSame(substr($this->sampleContent, 100, 100), $streamedData);
    }

    public function test_chunking_with_small_buffers(): void
    {
        // 1024 bytes chunk size on a 5000 bytes range
        $streamer = new BinaryStreamer(chunkSize: 1024);
        $range = ByteRange::partial(start: 0, end: 4999, total: strlen($this->sampleContent));

        $outputStream = fopen('php://memory', 'w+b');
        $bytesSent = $streamer->stream($this->tempFilePath, $range, $outputStream);

        $this->assertSame(5000, $bytesSent);

        rewind($outputStream);
        $data = stream_get_contents($outputStream);
        fclose($outputStream);

        $this->assertSame(substr($this->sampleContent, 0, 5000), $data);
    }

    public function test_stream_full_file(): void
    {
        $streamer = new BinaryStreamer();
        $total = strlen($this->sampleContent);
        $range = ByteRange::full($total);

        $outputStream = fopen('php://memory', 'w+b');
        $bytesSent = $streamer->stream($this->tempFilePath, $range, $outputStream);

        $this->assertSame($total, $bytesSent);

        rewind($outputStream);
        $data = stream_get_contents($outputStream);
        fclose($outputStream);

        $this->assertSame($this->sampleContent, $data);
    }

    public function test_creates_streamed_response_with_rfc7233_headers(): void
    {
        $streamer = new BinaryStreamer();
        $total = strlen($this->sampleContent);
        $range = ByteRange::partial(start: 50, end: 150, total: $total);

        $response = $streamer->toStreamedResponse(
            source: $this->tempFilePath,
            range: $range,
            headers: ['Content-Type' => 'video/mp4']
        );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('101', $response->headers->get('Content-Length'));
        $this->assertSame("bytes 50-150/{$total}", $response->headers->get('Content-Range'));
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
    }

    public function test_creates_streamed_response_for_full_file_without_content_range(): void
    {
        $streamer = new BinaryStreamer();
        $total = strlen($this->sampleContent);
        $range = ByteRange::full($total);

        $response = $streamer->toStreamedResponse(
            source: $this->tempFilePath,
            range: $range,
            headers: ['Content-Type' => 'audio/mpeg']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame((string) $total, $response->headers->get('Content-Length'));
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
    }

    public function test_throws_exception_on_non_existent_file(): void
    {
        $streamer = new BinaryStreamer();
        $range = ByteRange::full(100);

        $this->expectException(StreamResourceException::class);
        $streamer->stream('/non/existent/path/video.mp4', $range);
    }

    public function test_throws_exception_on_invalid_resource(): void
    {
        $streamer = new BinaryStreamer();
        $range = ByteRange::full(100);

        $this->expectException(StreamResourceException::class);
        $streamer->stream(12345, $range);
    }

    public function test_fluent_configuration_is_immutable(): void
    {
        $original = new BinaryStreamer(chunkSize: 65536);
        $modified = $original->withChunkSize(131072)->withThrottle(500);

        $this->assertSame(65536, $original->getChunkSize());
        $this->assertNull($original->getThrottle());

        $this->assertSame(131072, $modified->getChunkSize());
        $this->assertSame(500, $modified->getThrottle());
    }
}

