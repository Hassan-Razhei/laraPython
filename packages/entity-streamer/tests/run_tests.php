<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

// Register autoloader for EntityStreamer
spl_autoload_register(function ($class) {
    $prefix = 'EntityStreamer\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use EntityStreamer\Core\ByteRange;
use EntityStreamer\Core\RangeHeaderParser;
use EntityStreamer\Exceptions\InvalidByteRangeException;
use Symfony\Component\HttpFoundation\Request;

$passed = 0;
$failed = 0;

$it = function (string $description, callable $test) use (&$passed, &$failed) {
    try {
        $test();
        echo "\033[32m✔ PASS:\033[0m {$description}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "\033[31m✖ FAIL:\033[0m {$description}\n";
        echo "  \033[33mError: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\033[0m\n";
        $failed++;
    }
};

function it(string $description, callable $test) {
    global $it;
    $it($description, $test);
}

function assertEqual($expected, $actual, string $msg = '') {
    if ($expected !== $actual) {
        throw new \Exception("Assertion failed: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . " ({$msg})");
    }
}

function assertTrue($condition, string $msg = '') {
    if (!$condition) {
        throw new \Exception("Assertion failed: expected true, got false ({$msg})");
    }
}

function assertFalse($condition, string $msg = '') {
    if ($condition) {
        throw new \Exception("Assertion failed: expected false, got true ({$msg})");
    }
}

echo "\n=======================================================\n";
echo " 🚀 Running Level 1 Tests for entity-streamer\n";
echo "=======================================================\n\n";

// --- ByteRange Tests ---
it('ByteRange: can instantiate valid partial byte range', function () {
    $range = new ByteRange(start: 0, end: 1024, total: 5000, isPartial: true);
    assertEqual(0, $range->start);
    assertEqual(1024, $range->end);
    assertEqual(5000, $range->total);
    assertEqual(1025, $range->length);
    assertEqual(1025, $range->toContentLength());
    assertTrue($range->isPartial);
    assertEqual(206, $range->getStatusCode());
    assertEqual('bytes 0-1024/5000', $range->toContentRangeHeader());
    assertFalse($range->isFullFile());
});

it('ByteRange: can instantiate full byte range (HTTP 200)', function () {
    $range = ByteRange::full(5000);
    assertEqual(0, $range->start);
    assertEqual(4999, $range->end);
    assertEqual(5000, $range->total);
    assertEqual(5000, $range->length);
    assertFalse($range->isPartial);
    assertEqual(200, $range->getStatusCode());
    assertEqual('bytes 0-4999/5000', $range->toContentRangeHeader());
    assertTrue($range->isFullFile());
});

it('ByteRange: handles empty file safely', function () {
    $range = ByteRange::full(0);
    assertEqual(0, $range->start);
    assertEqual(0, $range->end);
    assertEqual(0, $range->total);
    assertEqual(0, $range->length);
    assertEqual('bytes */0', $range->toContentRangeHeader());
    assertEqual(200, $range->getStatusCode());
    assertTrue($range->isFullFile());
});

it('ByteRange: throws InvalidByteRangeException when start > end', function () {
    $threw = false;
    try {
        new ByteRange(start: 500, end: 400, total: 1000);
    } catch (InvalidByteRangeException $e) {
        $threw = true;
    }
    assertTrue($threw, 'Should throw when start > end');
});

it('ByteRange: throws InvalidByteRangeException when end >= total', function () {
    $threw = false;
    try {
        new ByteRange(start: 0, end: 1000, total: 1000);
    } catch (InvalidByteRangeException $e) {
        $threw = true;
    }
    assertTrue($threw, 'Should throw when end >= total');
});

it('ByteRange: throws InvalidByteRangeException when start < 0', function () {
    $threw = false;
    try {
        new ByteRange(start: -1, end: 500, total: 1000);
    } catch (InvalidByteRangeException $e) {
        $threw = true;
    }
    assertTrue($threw, 'Should throw when start < 0');
});

it('ByteRange: toArray contains all standard metrics', function () {
    $range = ByteRange::partial(start: 100, end: 200, total: 1000);
    $arr = $range->toArray();
    assertEqual(100, $arr['start']);
    assertEqual(200, $arr['end']);
    assertEqual(101, $arr['length']);
    assertEqual(1000, $arr['total']);
    assertTrue($arr['is_partial']);
    assertEqual(206, $arr['status_code']);
    assertEqual('bytes 100-200/1000', $arr['content_range']);
});

// --- RangeHeaderParser Tests ---
$parser = new RangeHeaderParser();

it('RangeHeaderParser: parses closed range (bytes=0-1024)', function () use ($parser) {
    $range = $parser->parse('bytes=0-1024', 50000);
    assertEqual(0, $range->start);
    assertEqual(1024, $range->end);
    assertEqual(1025, $range->length);
    assertEqual(50000, $range->total);
    assertTrue($range->isPartial);
    assertEqual(206, $range->getStatusCode());
    assertEqual('bytes 0-1024/50000', $range->toContentRangeHeader());
});

it('RangeHeaderParser: parses open-ended range (bytes=1048576-)', function () use ($parser) {
    $range = $parser->parse('bytes=1048576-', 2000000);
    assertEqual(1048576, $range->start);
    assertEqual(1999999, $range->end);
    assertEqual(2000000 - 1048576, $range->length);
    assertEqual(2000000, $range->total);
    assertTrue($range->isPartial);
    assertEqual(206, $range->getStatusCode());
});

it('RangeHeaderParser: parses suffix range (bytes=-500)', function () use ($parser) {
    $range = $parser->parse('bytes=-500', 10000);
    assertEqual(9500, $range->start);
    assertEqual(9999, $range->end);
    assertEqual(500, $range->length);
    assertEqual(10000, $range->total);
    assertTrue($range->isPartial);
    assertEqual(206, $range->getStatusCode());
    assertEqual('bytes 9500-9999/10000', $range->toContentRangeHeader());
});

it('RangeHeaderParser: suffix range larger than file returns entire file', function () use ($parser) {
    $range = $parser->parse('bytes=-2000', 1000);
    assertEqual(0, $range->start);
    assertEqual(999, $range->end);
    assertEqual(1000, $range->length);
    assertTrue($range->isPartial);
    assertEqual('bytes 0-999/1000', $range->toContentRangeHeader());
});

it('RangeHeaderParser: clamps end to total-1 when end exceeds total (RFC 7233 Section 2.1)', function () use ($parser) {
    $range = $parser->parse('bytes=100-999999', 5000);
    assertEqual(100, $range->start);
    assertEqual(4999, $range->end);
    assertEqual(4900, $range->length);
    assertEqual('bytes 100-4999/5000', $range->toContentRangeHeader());
});

it('RangeHeaderParser: returns full file (200 OK) when Range header is null or empty', function () use ($parser) {
    $rangeNull = $parser->parse(null, 5000);
    assertFalse($rangeNull->isPartial);
    assertEqual(200, $rangeNull->getStatusCode());
    assertEqual(0, $rangeNull->start);
    assertEqual(4999, $rangeNull->end);

    $rangeEmpty = $parser->parse('', 5000);
    assertFalse($rangeEmpty->isPartial);
    assertEqual(200, $rangeEmpty->getStatusCode());
});

it('RangeHeaderParser: ignores non-bytes units and serves 200 OK (RFC 7233)', function () use ($parser) {
    $range = $parser->parse('items=0-10', 5000);
    assertFalse($range->isPartial);
    assertEqual(200, $range->getStatusCode());
    assertEqual(0, $range->start);
    assertEqual(4999, $range->end);
});

it('RangeHeaderParser: takes first range when multiple ranges provided', function () use ($parser) {
    $range = $parser->parse('bytes=0-100, 200-300, 400-500', 5000);
    assertEqual(0, $range->start);
    assertEqual(100, $range->end);
    assertEqual(101, $range->length);
    assertTrue($range->isPartial);
});

it('RangeHeaderParser: throws 416 when start > end', function () use ($parser) {
    $threw = false;
    try {
        $parser->parse('bytes=500-200', 5000);
    } catch (InvalidByteRangeException $e) {
        $threw = true;
    }
    assertTrue($threw);
});

it('RangeHeaderParser: throws 416 when start is outside file size', function () use ($parser) {
    $threw = false;
    try {
        $parser->parse('bytes=6000-', 5000);
    } catch (InvalidByteRangeException $e) {
        $threw = true;
    }
    assertTrue($threw);
});

it('RangeHeaderParser: throws 416 when Range requested on empty file', function () use ($parser) {
    $threw = false;
    try {
        $parser->parse('bytes=0-10', 0);
    } catch (InvalidByteRangeException $e) {
        $threw = true;
    }
    assertTrue($threw);
});

it('RangeHeaderParser: exception generates compliant HTTP 416 response', function () use ($parser) {
    try {
        $parser->parse('bytes=5000-', 1000);
        throw new \Exception('Expected exception was not thrown');
    } catch (InvalidByteRangeException $e) {
        assertEqual(1000, $e->totalSize);
        assertEqual('bytes */1000', $e->toContentRangeHeader());

        $response = $e->toResponse();
        assertEqual(416, $response->getStatusCode());
        assertEqual('bytes */1000', $response->headers->get('Content-Range'));
        assertEqual('bytes', $response->headers->get('Accept-Ranges'));
        assertEqual('0', $response->headers->get('Content-Length'));
    }
});

it('RangeHeaderParser: parses directly from Symfony/Laravel Request', function () use ($parser) {
    $request = Request::create('/stream/video.mp4', 'GET', [], [], [], [
        'HTTP_RANGE' => 'bytes=200-800',
    ]);

    $range = $parser->parseRequest($request, 10000);
    assertEqual(200, $range->start);
    assertEqual(800, $range->end);
    assertEqual(601, $range->length);
    assertEqual(206, $range->getStatusCode());
});

echo "\n-------------------------------------------------------\n";
echo "📊 Test Results: {$passed} Passed, {$failed} Failed\n";
echo "-------------------------------------------------------\n\n";

if ($failed > 0) {
    exit(1);
}
