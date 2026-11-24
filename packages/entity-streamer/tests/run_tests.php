<?php

declare(strict_types=1);

require_once __DIR__.'/../../../vendor/autoload.php';

// Register autoloader for EntityStreamer
spl_autoload_register(function ($class) {
    $prefix = 'EntityStreamer\\';
    $baseDir = __DIR__.'/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir.str_replace('\\', '/', $relativeClass).'.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use EntityStreamer\Contracts\CanStreamMedia;
use EntityStreamer\Core\BinaryStreamer;
use EntityStreamer\Core\ByteRange;
use EntityStreamer\Core\RangeHeaderParser;
use EntityStreamer\Exceptions\InvalidByteRangeException;
use EntityStreamer\Exceptions\InvalidSignedStreamUrlException;
use EntityStreamer\Exceptions\MediaFileNotFoundException;
use EntityStreamer\Exceptions\PathTraversalException;
use EntityStreamer\Exceptions\StreamResourceException;
use EntityStreamer\Security\PathSanitizer;
use EntityStreamer\Security\StreamUrlSigner;
use EntityStreamer\Storage\StreamableFile;
use EntityStreamer\Storage\StreamableStorageAdapter;
use EntityStreamer\StreamerBuilder;
use EntityStreamer\StreamerManager;
use EntityStreamer\Support\MediaHeaderManager;
use EntityStreamer\Support\MimeDetector;
use EntityStreamer\Traits\HasStreamableMedia;
use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

$passed = 0;
$failed = 0;

$it = function (string $description, callable $test) use (&$passed, &$failed) {
    try {
        $test();
        echo "\033[32m✔ PASS:\033[0m {$description}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "\033[31m✖ FAIL:\033[0m {$description}\n";
        echo "  \033[33mError: ".$e->getMessage().' in '.$e->getFile().':'.$e->getLine()."\033[0m\n";
        $failed++;
    }
};

function it(string $description, callable $test)
{
    global $it;
    $it($description, $test);
}

function assertEqual($expected, $actual, string $msg = '')
{
    if ($expected !== $actual) {
        throw new Exception('Assertion failed: expected '.var_export($expected, true).', got '.var_export($actual, true)." ({$msg})");
    }
}

function assertTrue($condition, string $msg = '')
{
    if (! $condition) {
        throw new Exception("Assertion failed: expected true, got false ({$msg})");
    }
}

function assertFalse($condition, string $msg = '')
{
    if ($condition) {
        throw new Exception("Assertion failed: expected false, got true ({$msg})");
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
$parser = new RangeHeaderParser;

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
        throw new Exception('Expected exception was not thrown');
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

// --- Level 2: BinaryStreamer Tests ---
echo "\n=======================================================\n";
echo " 🚀 Running Level 2 Tests for entity-streamer (BinaryStreamer)\n";
echo "=======================================================\n\n";

$sampleText = str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@', 1600);
$tmpFile = tempnam(sys_get_temp_dir(), 'streamer_l2_');
file_put_contents($tmpFile, $sampleText);

it('BinaryStreamer: can stream exact byte range from file path', function () use ($tmpFile, $sampleText) {
    $streamer = new BinaryStreamer(chunkSize: 4096);
    $range = ByteRange::partial(start: 10, end: 35, total: strlen($sampleText));

    $outputStream = fopen('php://memory', 'w+b');
    $bytesSent = $streamer->stream($tmpFile, $range, $outputStream);

    assertEqual(26, $bytesSent);

    rewind($outputStream);
    $streamedData = stream_get_contents($outputStream);
    fclose($outputStream);

    $expectedData = substr($sampleText, 10, 26);
    assertEqual($expectedData, $streamedData);
});

it('BinaryStreamer: can stream from existing stream resource handle', function () use ($tmpFile, $sampleText) {
    $streamer = (new BinaryStreamer(chunkSize: 1024))->withoutBufferCleaning();
    $source = fopen($tmpFile, 'rb');
    $range = ByteRange::partial(start: 100, end: 199, total: strlen($sampleText));

    $outputStream = fopen('php://memory', 'w+b');
    $bytesSent = $streamer->stream($source, $range, $outputStream);

    assertEqual(100, $bytesSent);

    rewind($outputStream);
    $streamedData = stream_get_contents($outputStream);
    fclose($outputStream);
    fclose($source);

    assertEqual(substr($sampleText, 100, 100), $streamedData);
});

it('BinaryStreamer: chunking with small buffers (5000 bytes via 1KB chunks)', function () use ($tmpFile, $sampleText) {
    $streamer = new BinaryStreamer(chunkSize: 1024);
    $range = ByteRange::partial(start: 0, end: 4999, total: strlen($sampleText));

    $outputStream = fopen('php://memory', 'w+b');
    $bytesSent = $streamer->stream($tmpFile, $range, $outputStream);

    assertEqual(5000, $bytesSent);

    rewind($outputStream);
    $data = stream_get_contents($outputStream);
    fclose($outputStream);

    assertEqual(substr($sampleText, 0, 5000), $data);
});

it('BinaryStreamer: stream full file content (HTTP 200 representation)', function () use ($tmpFile, $sampleText) {
    $streamer = new BinaryStreamer;
    $total = strlen($sampleText);
    $range = ByteRange::full($total);

    $outputStream = fopen('php://memory', 'w+b');
    $bytesSent = $streamer->stream($tmpFile, $range, $outputStream);

    assertEqual($total, $bytesSent);

    rewind($outputStream);
    $data = stream_get_contents($outputStream);
    fclose($outputStream);

    assertEqual($sampleText, $data);
});

it('BinaryStreamer: creates StreamedResponse with RFC 7233 partial headers', function () use ($tmpFile, $sampleText) {
    $streamer = new BinaryStreamer;
    $total = strlen($sampleText);
    $range = ByteRange::partial(start: 50, end: 150, total: $total);

    $response = $streamer->toStreamedResponse(
        source: $tmpFile,
        range: $range,
        headers: ['Content-Type' => 'video/mp4']
    );

    assertEqual(206, $response->getStatusCode());
    assertEqual('bytes', $response->headers->get('Accept-Ranges'));
    assertEqual('101', $response->headers->get('Content-Length'));
    assertEqual("bytes 50-150/{$total}", $response->headers->get('Content-Range'));
    assertEqual('video/mp4', $response->headers->get('Content-Type'));
});

it('BinaryStreamer: creates StreamedResponse for full file (200 OK without Content-Range)', function () use ($tmpFile, $sampleText) {
    $streamer = new BinaryStreamer;
    $total = strlen($sampleText);
    $range = ByteRange::full($total);

    $response = $streamer->toStreamedResponse(
        source: $tmpFile,
        range: $range,
        headers: ['Content-Type' => 'audio/mpeg']
    );

    assertEqual(200, $response->getStatusCode());
    assertEqual('bytes', $response->headers->get('Accept-Ranges'));
    assertEqual((string) $total, $response->headers->get('Content-Length'));
    assertFalse($response->headers->has('Content-Range'));
    assertEqual('audio/mpeg', $response->headers->get('Content-Type'));
});

it('BinaryStreamer: throws StreamResourceException on missing file', function () {
    $streamer = new BinaryStreamer;
    $range = ByteRange::full(100);
    $threw = false;
    try {
        $streamer->stream('/non/existent/path/media.mp4', $range);
    } catch (StreamResourceException $e) {
        $threw = true;
    }
    assertTrue($threw);
});

it('BinaryStreamer: throws StreamResourceException on invalid resource type', function () {
    $streamer = new BinaryStreamer;
    $range = ByteRange::full(100);
    $threw = false;
    try {
        $streamer->stream(12345, $range);
    } catch (StreamResourceException $e) {
        $threw = true;
    }
    assertTrue($threw);
});

it('BinaryStreamer: fluent immutable configuration', function () {
    $orig = new BinaryStreamer(chunkSize: 65536);
    $mod = $orig->withChunkSize(131072)->withThrottle(500);

    assertEqual(65536, $orig->getChunkSize());
    assertEqual(null, $orig->getThrottle());
    assertEqual(131072, $mod->getChunkSize());
    assertEqual(500, $mod->getThrottle());
});

it('BinaryStreamer: memory safety test (zero-ballooning on multi-chunk stream)', function () use ($tmpFile, $sampleText) {
    $startMem = memory_get_usage();

    $streamer = new BinaryStreamer(chunkSize: 2048);
    $range = ByteRange::full(strlen($sampleText));

    // Stream to dev/null or null stream to check memory
    $output = fopen('php://temp', 'w+b');
    $streamer->stream($tmpFile, $range, $output);
    fclose($output);

    $endMem = memory_get_usage();
    // Memory overhead should be negligible (< 100 KB regardless of data streamed)
    $diff = abs($endMem - $startMem);
    assertTrue($diff < 200 * 1024, "Memory consumed ({$diff} bytes) is well within safety margin");
});

if (file_exists($tmpFile)) {
    unlink($tmpFile);
}

// --- Level 3: Storage Driver Agnosticism & Flysystem Tests ---
echo "\n=======================================================\n";
echo " 🚀 Running Level 3 Tests for entity-streamer (Storage Adapter)\n";
echo "=======================================================\n\n";

$tempStorageDir = sys_get_temp_dir().'/streamer_l3_'.uniqid();
mkdir($tempStorageDir, 0777, true);
$testFilePath = $tempStorageDir.'/audio.mp3';
file_put_contents($testFilePath, 'sample audio content');

$subDirPath = $tempStorageDir.'/videos';
mkdir($subDirPath, 0777, true);
$subFilePath = $subDirPath.'/intro.mp4';
file_put_contents($subFilePath, 'sample video content');

it('StorageAdapter: resolves local direct file', function () use ($testFilePath) {
    $adapter = new StreamableStorageAdapter;
    $file = $adapter->resolve($testFilePath);

    assertTrue($file instanceof StreamableFile);
    assertEqual($testFilePath, $file->path);
    assertEqual(strlen('sample audio content'), $file->size);
    assertTrue($file->isLocal());

    $stream = $file->openStream();
    assertTrue(is_resource($stream));
    assertEqual('sample audio content', stream_get_contents($stream));
    fclose($stream);
});

it('StorageAdapter: resolves via fallback directories on local disk', function () use ($tempStorageDir) {
    $adapter = (new StreamableStorageAdapter)
        ->withFallbackPrefixes([$tempStorageDir.'/videos']);

    $file = $adapter->resolve('intro.mp4');

    assertEqual($tempStorageDir.'/videos/intro.mp4', $file->path);
    assertEqual(strlen('sample video content'), $file->size);

    $stream = $file->openStream();
    assertEqual('sample video content', stream_get_contents($stream));
    fclose($stream);
});

it('StorageAdapter: resolves via custom Filesystem interface (simulated cloud disk)', function () {
    // Custom mock class implementing Filesystem
    $simulatedCloudFs = new class implements Filesystem
    {
        public function exists($path)
        {
            return $path === 'videos/nature.mp4';
        }

        public function get($path)
        {
            return 'simulated s3 content';
        }

        public function readStream($path)
        {
            $h = fopen('php://memory', 'w+b');
            fwrite($h, 'simulated s3 video stream');
            rewind($h);

            return $h;
        }

        public function put($path, $contents, $options = [])
        {
            return true;
        }

        public function writeStream($path, $resource, array $options = [])
        {
            return true;
        }

        public function getVisibility($path)
        {
            return 'public';
        }

        public function setVisibility($path, $visibility)
        {
            return true;
        }

        public function prepend($path, $data)
        {
            return true;
        }

        public function append($path, $data)
        {
            return true;
        }

        public function delete($paths)
        {
            return true;
        }

        public function copy($from, $to)
        {
            return true;
        }

        public function move($from, $to)
        {
            return true;
        }

        public function size($path)
        {
            return 1048576;
        }

        public function lastModified($path)
        {
            return 1700000000;
        }

        public function files($directory = null, $recursive = false)
        {
            return [];
        }

        public function allFiles($directory = null)
        {
            return [];
        }

        public function directories($directory = null, $recursive = false)
        {
            return [];
        }

        public function allDirectories($directory = null)
        {
            return [];
        }

        public function makeDirectory($path)
        {
            return true;
        }

        public function deleteDirectory($directory)
        {
            return true;
        }

        public function mimeType($path)
        {
            return 'video/mp4';
        }

        public function path($path)
        {
            return $path;
        }

        public function putFile($path, $file = null, $options = [])
        {
            return 'path';
        }

        public function putFileAs($path, $file, $name = null, $options = [])
        {
            return 'path';
        }
    };

    $adapter = (new StreamableStorageAdapter($simulatedCloudFs))
        ->withFallbackPrefixes(['videos/', 'imports/']);

    $file = $adapter->resolve('nature.mp4');

    assertEqual('videos/nature.mp4', $file->path);
    assertEqual(1048576, $file->size);
    assertEqual(1700000000, $file->lastModified);
    assertEqual('custom', $file->disk);
    assertEqual('video/mp4', $file->mimeType);

    $stream = $file->openStream();
    assertEqual('simulated s3 video stream', stream_get_contents($stream));
    fclose($stream);
});

it('StorageAdapter: throws MediaFileNotFoundException with attempted paths on missing file', function () {
    $adapter = (new StreamableStorageAdapter)
        ->withFallbackPrefixes(['videos/', 'imports/']);

    $threw = false;
    try {
        $adapter->resolve('missing_podcast.mp3');
    } catch (MediaFileNotFoundException $e) {
        $threw = true;
        assertEqual('missing_podcast.mp3', $e->path);
        assertEqual(['missing_podcast.mp3', 'videos/missing_podcast.mp3', 'imports/missing_podcast.mp3'], $e->attemptedPaths);
        assertEqual(404, $e->toResponse()->getStatusCode());
    }
    assertTrue($threw);
});

it('StorageAdapter: fluent configuration immutability', function () {
    $orig = new StreamableStorageAdapter('public', ['videos/']);
    $mod = $orig->withDisk('s3')->withFallbackPrefixes(['audio/', 'imports/'])->addFallbackPrefix('podcasts');

    assertEqual('public', $orig->getDiskName());
    assertEqual(['videos/'], $orig->getFallbackPrefixes());
    assertEqual('s3', $mod->getDiskName());
    assertEqual(['audio/', 'imports/', 'podcasts/'], $mod->getFallbackPrefixes());
});

// Cleanup temp files
if (file_exists($testFilePath)) {
    unlink($testFilePath);
}
if (file_exists($subFilePath)) {
    unlink($subFilePath);
}
if (is_dir($subDirPath)) {
    rmdir($subDirPath);
}
if (is_dir($tempStorageDir)) {
    rmdir($tempStorageDir);
}

// --- Level 4: Format Intelligence & Caching Tests ---
echo "\n=======================================================\n";
echo " 🚀 Running Level 4 Tests for entity-streamer (MIME & Headers)\n";
echo "=======================================================\n\n";

$mimeDetector = new MimeDetector;

it('MimeDetector: detects all major video extensions', function () use ($mimeDetector) {
    $videoExtensions = [
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
        'mkv' => 'video/x-matroska', 'mov' => 'video/quicktime', 'qt' => 'video/quicktime',
        'avi' => 'video/x-msvideo', 'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv',
        'ogv' => 'video/ogg', 'ts' => 'video/mp2t', '3gp' => 'video/3gpp',
        'mpeg' => 'video/mpeg', 'mpg' => 'video/mpeg',
    ];

    foreach ($videoExtensions as $ext => $expected) {
        $detected = $mimeDetector->detect("test.{$ext}");
        assertEqual($expected, $detected, "Extension {$ext}");
        assertTrue($mimeDetector->isVideo($detected));
        assertTrue($mimeDetector->isVideoExtension($ext));
    }
});

it('MimeDetector: detects all major audio extensions', function () use ($mimeDetector) {
    $audioExtensions = [
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
        'aac' => 'audio/aac', 'flac' => 'audio/flac', 'ogg' => 'audio/ogg',
        'opus' => 'audio/opus', 'weba' => 'audio/webm', 'wma' => 'audio/x-ms-wma',
        'aif' => 'audio/x-aiff', 'mid' => 'audio/midi', 'amr' => 'audio/amr',
    ];

    foreach ($audioExtensions as $ext => $expected) {
        $detected = $mimeDetector->detect("test.{$ext}");
        assertEqual($expected, $detected, "Extension {$ext}");
        assertTrue($mimeDetector->isAudio($detected));
        assertTrue($mimeDetector->isAudioExtension($ext));
    }
});

it('MimeDetector: detects from binary magic bytes', function () use ($mimeDetector) {
    // FLAC
    assertEqual('audio/flac', $mimeDetector->detect('unknown', "fLaC\x00\x00\x00\x22"));
    // WAV
    assertEqual('audio/wav', $mimeDetector->detect('unknown', "RIFF\x24\x00\x00\x00WAVEfmt "));
    // AVI
    assertEqual('video/x-msvideo', $mimeDetector->detect('unknown', "RIFF\x24\x00\x00\x00AVI LIST"));
    // MP4
    assertEqual('video/mp4', $mimeDetector->detect('unknown', "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00"));
    // M4A
    assertEqual('audio/mp4', $mimeDetector->detect('unknown', "\x00\x00\x00\x20ftypM4A \x00\x00\x02\x00"));
    // ID3 MP3
    assertEqual('audio/mpeg', $mimeDetector->detect('unknown', "ID3\x03\x00\x00\x00"));
    // EBML MKV
    assertEqual('video/x-matroska', $mimeDetector->detect('unknown', "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42matroska"));
    // Ogg
    assertEqual('audio/ogg', $mimeDetector->detect('unknown', "OggS\x00\x02\x00\x00"));
});

$headerManager = new MediaHeaderManager($mimeDetector);
$sampleFile = new StreamableFile(
    path: 'videos/شرح_المخطوطات.mp4',
    size: 5242880,
    lastModified: 1700000000,
    disk: 'public',
    mimeType: 'video/mp4'
);

it('MediaHeaderManager: generates ETag (weak and strong)', function () use ($headerManager, $sampleFile) {
    $weakEtag = $headerManager->generateEtag($sampleFile);
    assertTrue(str_starts_with($weakEtag, 'W/"'));
    assertTrue(str_ends_with($weakEtag, '"'));

    $strongMgr = (new MediaHeaderManager)->withWeakEtag(false);
    $strongEtag = $strongMgr->generateEtag($sampleFile);
    assertFalse(str_starts_with($strongEtag, 'W/'));
    assertEqual('"'.sprintf('%x-%x', 5242880, 1700000000).'"', $strongEtag);
});

it('MediaHeaderManager: formats Content-Disposition with UTF-8 RFC 5987', function () use ($headerManager) {
    $disposition = $headerManager->formatContentDisposition('شرح_المنظومة.mp4', inline: true);
    assertTrue(str_starts_with($disposition, 'inline;'));
    assertTrue(str_contains($disposition, "filename*=UTF-8''"));
    assertTrue(str_contains($disposition, rawurlencode('شرح_المنظومة.mp4')));

    $attachment = $headerManager->formatContentDisposition('audio.mp3', inline: false);
    assertTrue(str_starts_with($attachment, 'attachment;'));
});

it('MediaHeaderManager: conditional request returns 304 on matching If-None-Match', function () use ($headerManager, $sampleFile) {
    $etag = $headerManager->generateEtag($sampleFile);
    $request = Request::create('/stream/video.mp4', 'GET', [], [], [], [
        'HTTP_IF_NONE_MATCH' => $etag,
    ]);

    $response = $headerManager->checkNotModified($request, $sampleFile);
    assertTrue($response !== null);
    assertEqual(304, $response->getStatusCode());
    assertEqual($etag, $response->headers->get('ETag'));
    assertEqual('bytes', $response->headers->get('Accept-Ranges'));
});

it('MediaHeaderManager: conditional request returns 304 on valid If-Modified-Since', function () use ($headerManager, $sampleFile) {
    $rfcDate = gmdate('D, d M Y H:i:s \G\M\T', 1700000500); // 500s later than file
    $request = Request::create('/stream/video.mp4', 'GET', [], [], [], [
        'HTTP_IF_MODIFIED_SINCE' => $rfcDate,
    ]);

    $response = $headerManager->checkNotModified($request, $sampleFile);
    assertTrue($response !== null);
    assertEqual(304, $response->getStatusCode());
});

it('MediaHeaderManager: conditional request returns null when file is modified', function () use ($headerManager, $sampleFile) {
    $rfcDate = gmdate('D, d M Y H:i:s \G\M\T', 1600000000); // Older than file
    $request = Request::create('/stream/video.mp4', 'GET', [], [], [], [
        'HTTP_IF_MODIFIED_SINCE' => $rfcDate,
    ]);

    $response = $headerManager->checkNotModified($request, $sampleFile);
    assertTrue($response === null);
});

it('MediaHeaderManager: builds complete streaming headers array', function () use ($headerManager, $sampleFile) {
    $headers = $headerManager->buildHeaders($sampleFile, inline: true);
    assertEqual('video/mp4', $headers['Content-Type']);
    assertEqual('bytes', $headers['Accept-Ranges']);
    assertTrue(isset($headers['ETag']));
    assertTrue(isset($headers['Last-Modified']));
    assertEqual('public, max-age=86400, no-transform', $headers['Cache-Control']);
    assertTrue(str_starts_with($headers['Content-Disposition'], 'inline;'));
});

// --- Level 5: Security Shield & Signed URLs Tests ---
echo "\n=======================================================\n";
echo " 🚀 Running Level 5 Tests for entity-streamer (Security & Signer)\n";
echo "=======================================================\n\n";

$sanitizer = new PathSanitizer;

it('PathSanitizer: allows valid clean paths', function () use ($sanitizer) {
    assertEqual('videos/lecture1.mp4', $sanitizer->sanitize('videos/lecture1.mp4'));
    assertEqual('/audio/recitation.mp3', $sanitizer->sanitize('/audio/recitation.mp3'));
    assertEqual('deep/nested/storage/file.wav', $sanitizer->sanitize('deep//nested/storage/file.wav'));
    assertTrue($sanitizer->isSafe('videos/lecture.mp4'));
});

it('PathSanitizer: blocks classic directory traversal (..)', function () use ($sanitizer) {
    $threw = false;
    try {
        $sanitizer->sanitize('../secret.mp4');
    } catch (PathTraversalException $e) {
        $threw = true;
        assertEqual(403, $e->toResponse()->getStatusCode());
    }
    assertTrue($threw);
});

it('PathSanitizer: blocks nested and backslash traversal', function () use ($sanitizer) {
    $threwNested = false;
    try {
        $sanitizer->sanitize('videos/../../etc/passwd');
    } catch (PathTraversalException) {
        $threwNested = true;
    }
    assertTrue($threwNested);

    $threwWindows = false;
    try {
        $sanitizer->sanitize('videos\\..\\..\\windows\\system32');
    } catch (PathTraversalException) {
        $threwWindows = true;
    }
    assertTrue($threwWindows);
});

it('PathSanitizer: blocks multi-layer URL encoded traversal (%252e%252e)', function () use ($sanitizer) {
    $threw = false;
    try {
        $sanitizer->sanitize('%252e%252e/admin/video.mp4');
    } catch (PathTraversalException) {
        $threw = true;
    }
    assertTrue($threw);
});

it('PathSanitizer: blocks null byte injection', function () use ($sanitizer) {
    $threw = false;
    try {
        $sanitizer->sanitize("video.mp4\0.php");
    } catch (PathTraversalException) {
        $threw = true;
    }
    assertTrue($threw);
});

it('PathSanitizer: blocks sensitive dotfiles and Windows devices', function () use ($sanitizer) {
    assertFalse($sanitizer->isSafe('.env'));
    assertFalse($sanitizer->isSafe('.git'));
    assertFalse($sanitizer->isSafe('.htaccess'));
    assertFalse($sanitizer->isSafe('CON.mp4'));
    assertFalse($sanitizer->isSafe('NUL.mp3'));
});

$signer = new StreamUrlSigner('secret_test_key_1234567890_abcdef');

it('StreamUrlSigner: signs and validates media URL', function () use ($signer) {
    $path = 'videos/lecture-01.mp4';
    $signedUrl = $signer->sign($path, expiration: 3600);

    assertTrue(str_contains($signedUrl, 'signature='));
    assertTrue(str_contains($signedUrl, 'expires='));

    parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);
    assertTrue($signer->validate($path, $query));
});

it('StreamUrlSigner: rejects expired streaming signature', function () use ($signer) {
    $path = 'videos/lecture-01.mp4';
    $signedUrl = $signer->sign($path, expiration: time() - 50);

    parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

    $threw = false;
    try {
        $signer->validate($path, $query);
    } catch (InvalidSignedStreamUrlException $e) {
        $threw = true;
        assertEqual(403, $e->toResponse()->getStatusCode());
    }
    assertTrue($threw);
});

it('StreamUrlSigner: rejects tampered path or tampered parameters', function () use ($signer) {
    $path = 'videos/lecture-01.mp4';
    $signedUrl = $signer->sign($path, expiration: 3600);

    parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

    // Tampered path
    $threwPath = false;
    try {
        $signer->validate('videos/another-lecture.mp4', $query);
    } catch (InvalidSignedStreamUrlException) {
        $threwPath = true;
    }
    assertTrue($threwPath);

    // Tampered expiration
    $query['expires'] = (int) $query['expires'] + 5000;
    $threwExp = false;
    try {
        $signer->validate($path, $query);
    } catch (InvalidSignedStreamUrlException) {
        $threwExp = true;
    }
    assertTrue($threwExp);
});

it('StreamUrlSigner: IP-binding Anti-Leech protection', function () use ($signer) {
    $ipSigner = $signer->withIpBinding(true);
    $path = 'audio/quran.mp3';

    $signedUrl = $ipSigner->sign($path, expiration: 3600, extraParams: ['ip' => '192.168.1.100']);
    parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

    // Matching IP: success
    assertTrue($ipSigner->validate($path, $query, clientIp: '192.168.1.100'));

    // Different IP: forbidden (Anti-Leech)
    $threw = false;
    try {
        $ipSigner->validate($path, $query, clientIp: '10.0.0.1');
    } catch (InvalidSignedStreamUrlException) {
        $threw = true;
    }
    assertTrue($threw);
});

it('StreamUrlSigner: validates directly from Symfony/Laravel Request', function () use ($signer) {
    $path = 'videos/intro.mp4';
    $signedUrl = $signer->sign($path, expiration: 1800);
    parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

    $request = Request::create($signedUrl, 'GET', $query);
    assertTrue($signer->validateRequest($request, $path));
});

// --- Level 6: Eloquent Models & HasStreamableMedia Tests ---
echo "\n=======================================================\n";
echo " 🚀 Running Level 6 Tests for entity-streamer (Eloquent Trait)\n";
echo "=======================================================\n\n";

class TestDummyVideoModel implements CanStreamMedia
{
    use HasStreamableMedia;

    public function __construct(public array $attributes = []) {}

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}

class TestDummyMultiMediaModel implements CanStreamMedia
{
    use HasStreamableMedia;

    protected array $streamableMedia = [
        'default' => 'video_path',
        'trailer' => 'trailer_path',
        'audio' => 'audio_path',
    ];

    public function __construct(public array $attributes = []) {}

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}

$eloquentTempFile = tempnam(sys_get_temp_dir(), 'eloquent_test_');
$eloquentContent = str_repeat('Testing Eloquent Streaming Content! ', 300);
file_put_contents($eloquentTempFile, $eloquentContent);

it('HasStreamableMedia: discovers default media file column', function () use ($eloquentTempFile, $eloquentContent) {
    $model = new TestDummyVideoModel(['file_path' => $eloquentTempFile]);

    assertEqual($eloquentTempFile, $model->getStreamPath());
    $file = $model->toStreamableFile();

    assertTrue($file instanceof StreamableFile);
    assertEqual(strlen($eloquentContent), $file->size);
});

it('HasStreamableMedia: multi-channel media mapping', function () use ($eloquentTempFile) {
    $trailerFile = tempnam(sys_get_temp_dir(), 'trailer_test_');
    file_put_contents($trailerFile, 'trailer audio');

    $model = new TestDummyMultiMediaModel([
        'video_path' => $eloquentTempFile,
        'trailer_path' => $trailerFile,
    ]);

    assertEqual($eloquentTempFile, $model->getStreamPath('default'));
    assertEqual($trailerFile, $model->getStreamPath('trailer'));

    unlink($trailerFile);
});

it('HasStreamableMedia: throws StreamResourceException when column is missing', function () {
    $model = new TestDummyVideoModel(['title' => 'No media']);

    $threw = false;
    try {
        $model->getStreamPath();
    } catch (StreamResourceException $e) {
        $threw = true;
    }
    assertTrue($threw);
});

it('HasStreamableMedia: blocks path traversal in model attributes', function () {
    $model = new TestDummyVideoModel(['file_path' => '../passwords.txt']);

    $threw = false;
    try {
        $model->toStreamableFile();
    } catch (PathTraversalException $e) {
        $threw = true;
    }
    assertTrue($threw);
});

it('HasStreamableMedia: generates signed streaming URL', function () {
    $model = new TestDummyVideoModel(['file_path' => 'videos/lesson1.mp4']);
    $url = $model->getStreamUrl(expiration: 3600);

    assertTrue(str_contains($url, '/stream/videos/lesson1.mp4'));
    assertTrue(str_contains($url, 'signature='));
    assertTrue(str_contains($url, 'expires='));
});

it('HasStreamableMedia: streams RFC 7233 response directly from model', function () use ($eloquentTempFile) {
    $model = new TestDummyVideoModel(['file_path' => $eloquentTempFile]);

    $request = Request::create('/stream/video', 'GET', [], [], [], [
        'HTTP_RANGE' => 'bytes=0-100',
    ]);

    $response = $model->streamResponse($request);

    assertEqual(206, $response->getStatusCode());
    assertEqual('bytes', $response->headers->get('Accept-Ranges'));
    assertEqual('101', $response->headers->get('Content-Length'));
    assertTrue(str_starts_with((string) $response->headers->get('Content-Range'), 'bytes 0-100/'));
});

it('HasStreamableMedia: returns 304 Not Modified from model when matching ETag', function () use ($eloquentTempFile) {
    $model = new TestDummyVideoModel(['file_path' => $eloquentTempFile]);
    $file = $model->toStreamableFile();
    $etag = sprintf('W/"%x-%x"', $file->size, $file->lastModified);

    $request = Request::create('/stream/video', 'GET', [], [], [], [
        'HTTP_IF_NONE_MATCH' => $etag,
    ]);

    $response = $model->streamResponse($request);
    assertEqual(304, $response->getStatusCode());
});

if (file_exists($eloquentTempFile)) {
    unlink($eloquentTempFile);
}

// --- Level 7: StreamerManager & Facade Integration Tests ---
echo "\n=======================================================\n";
echo " 🚀 Running Level 7 Tests for entity-streamer (Manager & Facade)\n";
echo "=======================================================\n\n";

$facadeTempFile = tempnam(sys_get_temp_dir(), 'manager_test_');
$facadeContent = str_repeat('Testing Streamer Manager Level 7 Content! ', 200);
file_put_contents($facadeTempFile, $facadeContent);

$manager = new StreamerManager([
    'chunk_size' => 32768,
    'fallback_prefixes' => ['videos/', 'imports/'],
    'cache' => [
        'control' => 'public, max-age=7200',
        'weak_etag' => true,
    ],
    'security' => [
        'signature_ttl' => 1800,
        'bind_to_ip' => false,
    ],
]);

it('StreamerManager: returns fluent builder and resolves file', function () use ($manager, $facadeTempFile, $facadeContent) {
    $builder = $manager->stream($facadeTempFile);
    assertTrue($builder instanceof StreamerBuilder);

    $file = $builder->resolveFile();
    assertTrue($file instanceof StreamableFile);
    assertEqual(strlen($facadeContent), $file->size);
});

it('StreamerManager: fluent builder produces RFC 7233 partial response', function () use ($manager, $facadeTempFile) {
    $request = Request::create('/stream/media', 'GET', [], [], [], [
        'HTTP_RANGE' => 'bytes=100-300',
    ]);

    $response = $manager->stream($facadeTempFile)
        ->withChunkSize(4096)
        ->asInline('lecture.mp4')
        ->toResponse($request);

    assertEqual(206, $response->getStatusCode());
    assertEqual('bytes', $response->headers->get('Accept-Ranges'));
    assertEqual('201', $response->headers->get('Content-Length'));
    assertTrue(str_starts_with((string) $response->headers->get('Content-Range'), 'bytes 100-300/'));
    assertTrue(str_contains((string) $response->headers->get('Content-Disposition'), 'lecture.mp4'));
});

it('StreamerManager: signs and validates streaming URLs', function () use ($manager) {
    $path = 'videos/quran-recitation.mp4';
    $signedUrl = $manager->sign($path, expiration: 1800);

    assertTrue(str_contains($signedUrl, 'signature='));
    assertTrue(str_contains($signedUrl, 'expires='));

    parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);
    $request = Request::create($signedUrl, 'GET', $query);

    assertTrue($manager->validateSignature($request, $path));
});

it('StreamerManager: formats attachment mode with custom filename', function () use ($manager, $facadeTempFile) {
    $request = Request::create('/download', 'GET');

    $response = $manager->stream($facadeTempFile)
        ->asAttachment('downloaded_lecture.mp4')
        ->toResponse($request);

    assertEqual(200, $response->getStatusCode());
    assertTrue(str_starts_with((string) $response->headers->get('Content-Disposition'), 'attachment;'));
    assertTrue(str_contains((string) $response->headers->get('Content-Disposition'), 'downloaded_lecture.mp4'));
});

if (file_exists($facadeTempFile)) {
    unlink($facadeTempFile);
}

echo "\n=======================================================\n";
echo " 🚀 Running Level 8 Tests for entity-streamer (Enterprise & Stress)\n";
echo "=======================================================\n\n";

$stressParser = new RangeHeaderParser;
$stressSanitizer = new PathSanitizer;
$stressSigner = new StreamUrlSigner('stress-test-secret-key-32-chars-long!');

it('Enterprise Stress: zero-byte file full request returns 200 OK with length 0', function () use ($stressParser) {
    $range = $stressParser->parse(null, 0);
    assertFalse($range->isPartial);
    assertEqual(0, $range->length);
    assertEqual(0, $range->total);
});

it('Enterprise Stress: zero-byte file range request throws HTTP 416', function () use ($stressParser) {
    $caught = false;
    try {
        $stressParser->parse('bytes=0-0', 0);
    } catch (InvalidByteRangeException $e) {
        $caught = true;
        assertEqual(416, $e->getStatusCode());
    }
    assertTrue($caught, 'Should have thrown InvalidByteRangeException');
});

it('Enterprise Stress: single byte ranges at boundaries (first, middle, last)', function () use ($stressParser) {
    $fileSize = 1000;

    $first = $stressParser->parse('bytes=0-0', $fileSize);
    assertTrue($first->isPartial);
    assertEqual(0, $first->start);
    assertEqual(0, $first->end);
    assertEqual(1, $first->length);
    assertEqual('bytes 0-0/1000', $first->toContentRangeHeader());

    $middle = $stressParser->parse('bytes=500-500', $fileSize);
    assertTrue($middle->isPartial);
    assertEqual(500, $middle->start);
    assertEqual(500, $middle->end);
    assertEqual(1, $middle->length);
    assertEqual('bytes 500-500/1000', $middle->toContentRangeHeader());

    $last = $stressParser->parse('bytes=999-999', $fileSize);
    assertTrue($last->isPartial);
    assertEqual(999, $last->start);
    assertEqual(999, $last->end);
    assertEqual(1, $last->length);
    assertEqual('bytes 999-999/1000', $last->toContentRangeHeader());
});

it('Enterprise Stress: suffix range larger than file returns entire file', function () use ($stressParser) {
    $fileSize = 1000;
    $range = $stressParser->parse('bytes=-5000', $fileSize);

    assertTrue($range->isPartial);
    assertEqual(0, $range->start);
    assertEqual(999, $range->end);
    assertEqual(1000, $range->length);
    assertEqual('bytes 0-999/1000', $range->toContentRangeHeader());
});

it('Enterprise Stress: malformed range headers handling', function () use ($stressParser) {
    $fileSize = 1000;

    // Unrecognized empty range "bytes=-" falls back to full 200 OK per RFC 7233 Section 3.1
    $range = $stressParser->parse('bytes=-', $fileSize);
    assertFalse($range->isPartial);
    assertEqual(1000, $range->length);

    // Invalid non-positive suffix range throws 416
    $caught0 = false;
    try {
        $stressParser->parse('bytes=-0', $fileSize);
    } catch (InvalidByteRangeException $e) {
        $caught0 = true;
        assertEqual(416, $e->getStatusCode());
    }
    assertTrue($caught0, 'Expected InvalidByteRangeException for bytes=-0');

    // Reversed range (start > end) throws 416
    $caught2 = false;
    try {
        $stressParser->parse('bytes=600-400', $fileSize);
    } catch (InvalidByteRangeException $e) {
        $caught2 = true;
        assertEqual(416, $e->getStatusCode());
    }
    assertTrue($caught2, 'Expected InvalidByteRangeException for start > end');

    // Start out of bounds throws 416
    $caught3 = false;
    try {
        $stressParser->parse('bytes=1500-2000', $fileSize);
    } catch (InvalidByteRangeException $e) {
        $caught3 = true;
        assertEqual(416, $e->getStatusCode());
    }
    assertTrue($caught3, 'Expected InvalidByteRangeException for start >= total');
});

it('Enterprise Stress: range header with irregular whitespace and mixed casing', function () use ($stressParser) {
    $fileSize = 1000;
    $range = $stressParser->parse('  BYTES  =  50 - 150  ', $fileSize);

    assertTrue($range->isPartial);
    assertEqual(50, $range->start);
    assertEqual(150, $range->end);
    assertEqual(101, $range->length);
});

it('Enterprise Stress: memory bounded streaming on 2MB stream (memory delta < 1MB)', function () use ($stressParser) {
    $tempFile = tempnam(sys_get_temp_dir(), 'stream_mem_');
    $handle = fopen($tempFile, 'w+b');
    $chunk = str_repeat('A', 8192); // 8KB block
    for ($i = 0; $i < 256; $i++) {
        fwrite($handle, $chunk);
    }
    fclose($handle);

    $streamer = new BinaryStreamer(chunkSize: 4096);
    $range = $stressParser->parse(null, 2 * 1024 * 1024);

    $initialMemory = memory_get_usage();
    $target = fopen('php://temp', 'w+b');

    $streamedBytes = $streamer->stream($tempFile, $range, $target);
    $currentMemory = memory_get_usage();
    $memoryDelta = $currentMemory - $initialMemory;

    fclose($target);
    unlink($tempFile);

    assertTrue($memoryDelta < 1024 * 1024, 'Memory ballooned during chunk streaming');
    assertEqual(2 * 1024 * 1024, $streamedBytes);
});

it('Enterprise Stress: bidirectional seeking accuracy (forward, backward, suffix)', function () use ($stressParser) {
    $content = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $tempFile = tempnam(sys_get_temp_dir(), 'seek_test_');
    file_put_contents($tempFile, $content);

    $streamer = new BinaryStreamer(chunkSize: 16);
    $total = strlen($content);

    // Forward seek: byte 10 to 19 ('ABCDEFGHIJ')
    $range1 = $stressParser->parse('bytes=10-19', $total);
    $target1 = fopen('php://temp', 'w+b');
    $streamer->stream($tempFile, $range1, $target1);
    rewind($target1);
    assertEqual('ABCDEFGHIJ', stream_get_contents($target1));
    fclose($target1);

    // Backward seek: byte 0 to 4 ('01234')
    $range2 = $stressParser->parse('bytes=0-4', $total);
    $target2 = fopen('php://temp', 'w+b');
    $streamer->stream($tempFile, $range2, $target2);
    rewind($target2);
    assertEqual('01234', stream_get_contents($target2));
    fclose($target2);

    // Far end seek: last 5 bytes ('vwxyz')
    $range3 = $stressParser->parse('bytes=-5', $total);
    $target3 = fopen('php://temp', 'w+b');
    $streamer->stream($tempFile, $range3, $target3);
    rewind($target3);
    assertEqual('vwxyz', stream_get_contents($target3));
    fclose($target3);

    unlink($tempFile);
});

it('Enterprise Stress: advanced path traversal fuzzing (multi-encoded, null-bytes, devices, dotfiles)', function () use ($stressSanitizer) {
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
        $blocked = false;
        try {
            $stressSanitizer->sanitize($path);
        } catch (PathTraversalException $e) {
            $blocked = true;
            assertEqual(403, $e->getStatusCode());
        }
        assertTrue($blocked, "Expected PathTraversalException for path: {$path}");
    }
});

it('Enterprise Stress: signed url anti-tamper (tampered IP, tampered signature, expired)', function () use ($stressSigner) {
    $path = 'courses/lesson1.mp4';
    $signerWithIp = $stressSigner->withIpBinding(true);
    $signedUrl = $signerWithIp->sign($path, expiration: 3600, extraParams: ['ip' => '192.168.1.100']);

    parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);

    // 1. Valid request
    $validRequest = Request::create($signedUrl, 'GET', $query, server: ['REMOTE_ADDR' => '192.168.1.100']);
    assertTrue($signerWithIp->isValidRequest($validRequest, $path));

    // 2. Tampered IP (Anti-Leech)
    $leechedRequest = Request::create($signedUrl, 'GET', $query, server: ['REMOTE_ADDR' => '10.0.0.1']);
    assertFalse($signerWithIp->isValidRequest($leechedRequest, $path));

    // 3. Tampered signature character
    $tamperedQuery = $query;
    $tamperedQuery['signature'] = substr($query['signature'], 0, -2).'ab';
    $tamperedSigRequest = Request::create('/stream', 'GET', $tamperedQuery, server: ['REMOTE_ADDR' => '192.168.1.100']);
    assertFalse($signerWithIp->isValidRequest($tamperedSigRequest, $path));

    // 4. Expired signature
    $expiredUrl = $stressSigner->sign($path, expiration: -10);
    parse_str(parse_url($expiredUrl, PHP_URL_QUERY), $expiredQuery);
    $expiredRequest = Request::create($expiredUrl, 'GET', $expiredQuery);
    assertFalse($stressSigner->isValidRequest($expiredRequest, $path));
});

it('Enterprise Stress: HTML5 media player lifecycle (probe 0-1, seek chunk, conditional cache 304)', function () {
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

    assertEqual(206, $probeResponse->getStatusCode());
    assertEqual('bytes', $probeResponse->headers->get('Accept-Ranges'));
    assertEqual('2', $probeResponse->headers->get('Content-Length'));
    assertEqual('bytes 0-1/100000', $probeResponse->headers->get('Content-Range'));
    $etag = $probeResponse->headers->get('ETag');
    assertTrue(! empty($etag));

    // Phase 2: User seeks to byte 50000-60000
    $seekRequest = Request::create('/video/watch', 'GET', [], [], [], [
        'HTTP_RANGE' => 'bytes=50000-60000',
    ]);
    $seekResponse = $manager->stream($tempFile)->toResponse($seekRequest);

    assertEqual(206, $seekResponse->getStatusCode());
    assertEqual('10001', $seekResponse->headers->get('Content-Length'));
    assertEqual('bytes 50000-60000/100000', $seekResponse->headers->get('Content-Range'));

    // Phase 3: Browser checks cache with If-None-Match
    $cacheRequest = Request::create('/video/watch', 'GET', [], [], [], [
        'HTTP_IF_NONE_MATCH' => $etag,
    ]);
    $cacheResponse = $manager->stream($tempFile)->toResponse($cacheRequest);

    assertEqual(304, $cacheResponse->getStatusCode());

    unlink($tempFile);
});

echo "\n-------------------------------------------------------\n";
echo "📊 Test Results: {$passed} Passed, {$failed} Failed\n";
echo "-------------------------------------------------------\n\n";

if ($failed > 0) {
    exit(1);
}
