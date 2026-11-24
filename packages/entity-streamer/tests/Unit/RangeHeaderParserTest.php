<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Core\RangeHeaderParser;
use EntityStreamer\Exceptions\InvalidByteRangeException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class RangeHeaderParserTest extends TestCase
{
    private RangeHeaderParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new RangeHeaderParser();
    }

    public function test_parses_closed_range_correctly(): void
    {
        $range = $this->parser->parse('bytes=0-1024', 50000);

        $this->assertSame(0, $range->start);
        $this->assertSame(1024, $range->end);
        $this->assertSame(1025, $range->length);
        $this->assertSame(50000, $range->total);
        $this->assertTrue($range->isPartial);
        $this->assertSame(206, $range->getStatusCode());
        $this->assertSame('bytes 0-1024/50000', $range->toContentRangeHeader());
    }

    public function test_parses_open_ended_range_correctly(): void
    {
        $range = $this->parser->parse('bytes=1048576-', 2000000);

        $this->assertSame(1048576, $range->start);
        $this->assertSame(1999999, $range->end);
        $this->assertSame(2000000 - 1048576, $range->length);
        $this->assertSame(2000000, $range->total);
        $this->assertTrue($range->isPartial);
        $this->assertSame(206, $range->getStatusCode());
    }

    public function test_parses_suffix_range_correctly(): void
    {
        $range = $this->parser->parse('bytes=-500', 10000);

        $this->assertSame(9500, $range->start);
        $this->assertSame(9999, $range->end);
        $this->assertSame(500, $range->length);
        $this->assertSame(10000, $range->total);
        $this->assertTrue($range->isPartial);
        $this->assertSame(206, $range->getStatusCode());
        $this->assertSame('bytes 9500-9999/10000', $range->toContentRangeHeader());
    }

    public function test_suffix_range_larger_than_file_returns_entire_file(): void
    {
        $range = $this->parser->parse('bytes=-2000', 1000);

        $this->assertSame(0, $range->start);
        $this->assertSame(999, $range->end);
        $this->assertSame(1000, $range->length);
        $this->assertTrue($range->isPartial);
        $this->assertSame('bytes 0-999/1000', $range->toContentRangeHeader());
    }

    public function test_clamps_end_to_total_minus_one_when_end_exceeds_total_per_rfc7233(): void
    {
        $range = $this->parser->parse('bytes=100-999999', 5000);

        $this->assertSame(100, $range->start);
        $this->assertSame(4999, $range->end);
        $this->assertSame(4900, $range->length);
        $this->assertSame('bytes 100-4999/5000', $range->toContentRangeHeader());
    }

    public function test_returns_full_file_when_header_is_null_or_empty(): void
    {
        $rangeNull = $this->parser->parse(null, 5000);
        $this->assertFalse($rangeNull->isPartial);
        $this->assertSame(200, $rangeNull->getStatusCode());
        $this->assertSame(0, $rangeNull->start);
        $this->assertSame(4999, $rangeNull->end);

        $rangeEmpty = $this->parser->parse('', 5000);
        $this->assertFalse($rangeEmpty->isPartial);
        $this->assertSame(200, $rangeEmpty->getStatusCode());
    }

    public function test_ignores_non_bytes_units_and_serves_full_content_per_rfc7233(): void
    {
        $range = $this->parser->parse('items=0-10', 5000);

        $this->assertFalse($range->isPartial);
        $this->assertSame(200, $range->getStatusCode());
        $this->assertSame(0, $range->start);
        $this->assertSame(4999, $range->end);
    }

    public function test_takes_first_range_when_multiple_ranges_are_provided(): void
    {
        $range = $this->parser->parse('bytes=0-100, 200-300, 400-500', 5000);

        $this->assertSame(0, $range->start);
        $this->assertSame(100, $range->end);
        $this->assertSame(101, $range->length);
        $this->assertTrue($range->isPartial);
    }

    public function test_throws_416_when_start_is_greater_than_end(): void
    {
        $this->expectException(InvalidByteRangeException::class);
        $this->parser->parse('bytes=500-200', 5000);
    }

    public function test_throws_416_when_start_is_outside_file_size(): void
    {
        $this->expectException(InvalidByteRangeException::class);
        $this->parser->parse('bytes=6000-', 5000);
    }

    public function test_throws_416_when_range_requested_on_empty_file(): void
    {
        $this->expectException(InvalidByteRangeException::class);
        $this->parser->parse('bytes=0-10', 0);
    }

    public function test_exception_generates_correct_http_416_response(): void
    {
        try {
            $this->parser->parse('bytes=5000-', 1000);
            $this->fail('Expected InvalidByteRangeException was not thrown');
        } catch (InvalidByteRangeException $e) {
            $this->assertSame(1000, $e->totalSize);
            $this->assertSame('bytes */1000', $e->toContentRangeHeader());

            $response = $e->toResponse();
            $this->assertSame(416, $response->getStatusCode());
            $this->assertSame('bytes */1000', $response->headers->get('Content-Range'));
            $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        }
    }

    public function test_parses_directly_from_http_request(): void
    {
        $request = Request::create('/stream/video.mp4', 'GET', [], [], [], [
            'HTTP_RANGE' => 'bytes=200-800',
        ]);

        $range = $this->parser->parseRequest($request, 10000);

        $this->assertSame(200, $range->start);
        $this->assertSame(800, $range->end);
        $this->assertSame(601, $range->length);
        $this->assertSame(206, $range->getStatusCode());
    }
}

