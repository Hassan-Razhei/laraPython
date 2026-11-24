<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Core\BinaryStreamer;
use EntityStreamer\Core\RangeHeaderParser;
use EntityStreamer\Exceptions\InvalidByteRangeException;
use EntityStreamer\Exceptions\PathTraversalException;
use EntityStreamer\Security\PathSanitizer;
use EntityStreamer\Security\StreamUrlSigner;
use EntityStreamer\StreamerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class EnterpriseStressTest extends TestCase
{
    private RangeHeaderParser $parser;

    private PathSanitizer $sanitizer;

    private StreamUrlSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new RangeHeaderParser;
        $this->sanitizer = new PathSanitizer;
        $this->signer = new StreamUrlSigner('stress-test-secret-key-32-chars-long!');
    }

    public function test_zero_byte_file_full_request_returns_200(): void
    {
        $range = $this->parser->parse(null, 0);

        $this->assertFalse($range->isPartial);
        $this->assertSame(0, $range->length);
        $this->assertSame(0, $range->total);
    }

    public function test_zero_byte_file_range_request_throws_416(): void
    {
        $this->expectException(InvalidByteRangeException::class);
        $this->parser->parse('bytes=0-0', 0);
    }

    public function test_single_byte_ranges_at_boundaries(): void
    {
        $fileSize = 1000;

        // 1. First byte
        $first = $this->parser->parse('bytes=0-0', $fileSize);
        $this->assertTrue($first->isPartial);
        $this->assertSame(0, $first->start);
        $this->assertSame(0, $first->end);
        $this->assertSame(1, $first->length);
        $this->assertSame('bytes 0-0/1000', $first->toContentRangeHeader());

        // 2. Middle byte
        $middle = $this->parser->parse('bytes=500-500', $fileSize);
        $this->assertTrue($middle->isPartial);
        $this->assertSame(500, $middle->start);
        $this->assertSame(500, $middle->end);
        $this->assertSame(1, $middle->length);
        $this->assertSame('bytes 500-500/1000', $middle->toContentRangeHeader());

        // 3. Last byte
        $last = $this->parser->parse('bytes=999-999', $fileSize);
        $this->assertTrue($last->isPartial);
        $this->assertSame(999, $last->start);
        $this->assertSame(999, $last->end);
        $this->assertSame(1, $last->length);
        $this->assertSame('bytes 999-999/1000', $last->toContentRangeHeader());
    }

    public function test_suffix_larger_than_total_size_returns_entire_file(): void
    {
        $fileSize = 1000;
        $range = $this->parser->parse('bytes=-5000', $fileSize);

        $this->assertTrue($range->isPartial);
        $this->assertSame(0, $range->start);
        $this->assertSame(999, $range->end);
        $this->assertSame(1000, $range->length);
        $this->assertSame('bytes 0-999/1000', $range->toContentRangeHeader());
    }

    public function test_malformed_range_headers(): void
    {
        $fileSize = 1000;

        // Unrecognized empty range "bytes=-" falls back to full 200 OK per RFC 7233 Section 3.1
        $range = $this->parser->parse('bytes=-', $fileSize);
        $this->assertFalse($range->isPartial);
        $this->assertSame(1000, $range->length);

        // Invalid non-positive suffix range throws 416
        try {
            $this->parser->parse('bytes=-0', $fileSize);
            $this->fail('Expected InvalidByteRangeException for bytes=-0');
        } catch (InvalidByteRangeException $e) {
            $this->assertSame(416, $e->getStatusCode());
        }

        // Reversed range (start > end) throws 416
        try {
            $this->parser->parse('bytes=600-400', $fileSize);
            $this->fail('Expected InvalidByteRangeException for start > end');
        } catch (InvalidByteRangeException $e) {
            $this->assertSame(416, $e->getStatusCode());
        }

        // Start out of bounds throws 416
        try {
            $this->parser->parse('bytes=1500-2000', $fileSize);
            $this->fail('Expected InvalidByteRangeException for start >= total');
        } catch (InvalidByteRangeException $e) {
            $this->assertSame(416, $e->getStatusCode());
        }
    }

    public function test_range_header_with_spaces_and_casing(): void
    {
        $fileSize = 1000;
        $range = $this->parser->parse('  BYTES  =  50 - 150  ', $fileSize);

        $this->assertTrue($range->isPartial);
        $this->assertSame(50, $range->start);
        $this->assertSame(150, $range->end);
        $this->assertSame(101, $range->length);
    }

    public function test_memory_bounded_streaming_on_large_virtual_stream(): void
    {
        // Generate temporary 2MB file
        $tempFile = tempnam(sys_get_temp_dir(), 'stream_mem_');
        $handle = fopen($tempFile, 'w+b');
        $chunk = str_repeat('A', 8192); // 8KB block
        for ($i = 0; $i < 256; $i++) {
            fwrite($handle, $chunk);
        }
        fclose($handle);

        $streamer = new BinaryStreamer(chunkSize: 4096);
        $range = $this->parser->parse(null, 2 * 1024 * 1024);

        $initialMemory = memory_get_usage();
        $outputTarget = fopen('php://temp', 'w+b');

        $streamedBytes = $streamer->stream($tempFile, $range, $outputTarget);
        $currentMemory = memory_get_usage();
        $memoryDelta = $currentMemory - $initialMemory;

        fclose($outputTarget);
        unlink($tempFile);

        // Memory delta during chunking must remain below 1MB
        $this->assertLessThan(1024 * 1024, $memoryDelta, 'Memory ballooned during streaming');
        $this->assertSame(2 * 1024 * 1024, $streamedBytes);
    }

    public function test_bidirectional_seeking_accuracy(): void
    {
        $content = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $tempFile = tempnam(sys_get_temp_dir(), 'seek_test_');
        file_put_contents($tempFile, $content);

        $streamer = new BinaryStreamer(chunkSize: 16);
        $total = strlen($content);

        // 1. Forward seek: byte 10 to 19 ('ABCDEFGHIJ')
        $range1 = $this->parser->parse('bytes=10-19', $total);
        $target1 = fopen('php://temp', 'w+b');
        $streamer->stream($tempFile, $range1, $target1);
        rewind($target1);
        $this->assertSame('ABCDEFGHIJ', stream_get_contents($target1));
        fclose($target1);

        // 2. Backward seek: byte 0 to 4 ('01234')
        $range2 = $this->parser->parse('bytes=0-4', $total);
        $target2 = fopen('php://temp', 'w+b');
        $streamer->stream($tempFile, $range2, $target2);
        rewind($target2);
        $this->assertSame('01234', stream_get_contents($target2));
        fclose($target2);

        // 3. Far end seek: last 5 bytes ('vwxyz')
        $range3 = $this->parser->parse('bytes=-5', $total);
        $target3 = fopen('php://temp', 'w+b');
        $streamer->stream($tempFile, $range3, $target3);
        rewind($target3);
        $this->assertSame('vwxyz', stream_get_contents($target3));
        fclose($target3);

        unlink($tempFile);
    }

    public function test_advanced_path_traversal_fuzzing(): void
    {
        $maliciousPaths = [
            '%25252e%25252e%25252fetc%25252fpasswd',
            'videos/../../../etc/shadow',
            'media/\0hidden.mp4',
            '\0evil_script.sh',
            'CON',
            'PRN',
            'AUX',
            'NUL',
            'COM1',
            '.git/HEAD',
            'config/.env',
        ];

        foreach ($maliciousPaths as $path) {
            try {
                $this->sanitizer->sanitize($path);
                $this->fail("Expected PathTraversalException for path: {$path}");
            } catch (PathTraversalException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_signed_url_anti_tamper_stress(): void
    {
        $path = 'courses/lesson1.mp4';
        $signerWithIp = $this->signer->withIpBinding(true);
        $signedUrl = $signerWithIp->sign($path, expiration: 3600, extraParams: ['ip' => '192.168.1.100']);

        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

        // 1. Valid request with matching IP
        $validRequest = Request::create($signedUrl, 'GET', $query, server: ['REMOTE_ADDR' => '192.168.1.100']);
        $this->assertTrue($signerWithIp->isValidRequest($validRequest, $path));

        // 2. Tampered IP (Anti-Leech)
        $leechedRequest = Request::create($signedUrl, 'GET', $query, server: ['REMOTE_ADDR' => '10.0.0.1']);
        $this->assertFalse($signerWithIp->isValidRequest($leechedRequest, $path));

        // 3. Tampered signature character
        $tamperedQuery = $query;
        $tamperedQuery['signature'] = substr($query['signature'], 0, -2).'ab';
        $tamperedSigRequest = Request::create('/stream', 'GET', $tamperedQuery, server: ['REMOTE_ADDR' => '192.168.1.100']);
        $this->assertFalse($signerWithIp->isValidRequest($tamperedSigRequest, $path));

        // 4. Expired signature
        $expiredUrl = $this->signer->sign($path, expiration: -10);
        parse_str(parse_url($expiredUrl, PHP_URL_QUERY), $expiredQuery);
        $expiredRequest = Request::create($expiredUrl, 'GET', $expiredQuery);
        $this->assertFalse($this->signer->isValidRequest($expiredRequest, $path));
    }

    public function test_html5_media_player_probe_and_seek_flow(): void
    {
        $videoData = str_repeat('V', 100000); // 100KB test video
        $tempFile = tempnam(sys_get_temp_dir(), 'video_player_');
        file_put_contents($tempFile, $videoData);

        $manager = new StreamerManager([
            'disk' => 'local',
            'chunk_size' => 4096,
        ]);

        // Phase 1: Browser probes metadata with Range: bytes=0-1
        $probeRequest = Request::create('/video/watch', 'GET', [], [], [], [
            'HTTP_RANGE' => 'bytes=0-1',
        ]);
        $probeResponse = $manager->stream($tempFile)->toResponse($probeRequest);

        $this->assertSame(206, $probeResponse->getStatusCode());
        $this->assertSame('bytes', $probeResponse->headers->get('Accept-Ranges'));
        $this->assertSame('2', $probeResponse->headers->get('Content-Length'));
        $this->assertSame('bytes 0-1/100000', $probeResponse->headers->get('Content-Range'));
        $etag = $probeResponse->headers->get('ETag');
        $this->assertNotEmpty($etag);

        // Phase 2: User seeks to byte 50000-60000
        $seekRequest = Request::create('/video/watch', 'GET', [], [], [], [
            'HTTP_RANGE' => 'bytes=50000-60000',
        ]);
        $seekResponse = $manager->stream($tempFile)->toResponse($seekRequest);

        $this->assertSame(206, $seekResponse->getStatusCode());
        $this->assertSame('10001', $seekResponse->headers->get('Content-Length'));
        $this->assertSame('bytes 50000-60000/100000', $seekResponse->headers->get('Content-Range'));

        // Phase 3: Browser checks cache with If-None-Match
        $cacheRequest = Request::create('/video/watch', 'GET', [], [], [], [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);
        $cacheResponse = $manager->stream($tempFile)->toResponse($cacheRequest);

        $this->assertSame(304, $cacheResponse->getStatusCode());

        unlink($tempFile);
    }
}
