<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Core\ByteRange;
use EntityStreamer\Exceptions\InvalidByteRangeException;
use PHPUnit\Framework\TestCase;

class ByteRangeTest extends TestCase
{
    public function test_can_instantiate_valid_partial_byte_range(): void
    {
        $range = new ByteRange(start: 0, end: 1024, total: 5000, isPartial: true);

        $this->assertSame(0, $range->start);
        $this->assertSame(1024, $range->end);
        $this->assertSame(5000, $range->total);
        $this->assertSame(1025, $range->length);
        $this->assertSame(1025, $range->toContentLength());
        $this->assertTrue($range->isPartial);
        $this->assertSame(206, $range->getStatusCode());
        $this->assertSame('bytes 0-1024/5000', $range->toContentRangeHeader());
        $this->assertFalse($range->isFullFile());
    }

    public function test_can_instantiate_full_byte_range(): void
    {
        $range = ByteRange::full(5000);

        $this->assertSame(0, $range->start);
        $this->assertSame(4999, $range->end);
        $this->assertSame(5000, $range->total);
        $this->assertSame(5000, $range->length);
        $this->assertFalse($range->isPartial);
        $this->assertSame(200, $range->getStatusCode());
        $this->assertSame('bytes 0-4999/5000', $range->toContentRangeHeader());
        $this->assertTrue($range->isFullFile());
    }

    public function test_handles_empty_file(): void
    {
        $range = ByteRange::full(0);

        $this->assertSame(0, $range->start);
        $this->assertSame(0, $range->end);
        $this->assertSame(0, $range->total);
        $this->assertSame(0, $range->length);
        $this->assertSame('bytes */0', $range->toContentRangeHeader());
        $this->assertSame(200, $range->getStatusCode());
        $this->assertTrue($range->isFullFile());
    }

    public function test_throws_exception_if_start_is_greater_than_end(): void
    {
        $this->expectException(InvalidByteRangeException::class);
        new ByteRange(start: 500, end: 400, total: 1000);
    }

    public function test_throws_exception_if_end_is_greater_or_equal_to_total(): void
    {
        $this->expectException(InvalidByteRangeException::class);
        new ByteRange(start: 0, end: 1000, total: 1000);
    }

    public function test_throws_exception_if_start_is_negative(): void
    {
        $this->expectException(InvalidByteRangeException::class);
        new ByteRange(start: -1, end: 500, total: 1000);
    }

    public function test_to_array_contains_all_metrics(): void
    {
        $range = ByteRange::partial(start: 100, end: 200, total: 1000);
        $array = $range->toArray();

        $this->assertSame(100, $array['start']);
        $this->assertSame(200, $array['end']);
        $this->assertSame(101, $array['length']);
        $this->assertSame(1000, $array['total']);
        $this->assertTrue($array['is_partial']);
        $this->assertSame(206, $array['status_code']);
        $this->assertSame('bytes 100-200/1000', $array['content_range']);
    }
}

