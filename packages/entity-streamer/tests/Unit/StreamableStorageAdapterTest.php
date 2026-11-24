<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Exceptions\MediaFileNotFoundException;
use EntityStreamer\Storage\StreamableFile;
use EntityStreamer\Storage\StreamableStorageAdapter;
use Illuminate\Contracts\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class StreamableStorageAdapterTest extends TestCase
{
    private string $tempDir;
    private string $testFilePath;
    private string $subDirPath;
    private string $subFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/streamer_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // Root file
        $this->testFilePath = $this->tempDir . '/audio.mp3';
        file_put_contents($this->testFilePath, 'sample audio content');

        // Subdirectory fallback file
        $this->subDirPath = $this->tempDir . '/videos';
        mkdir($this->subDirPath, 0777, true);
        $this->subFilePath = $this->subDirPath . '/intro.mp4';
        file_put_contents($this->subFilePath, 'sample video content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFilePath)) {
            unlink($this->testFilePath);
        }
        if (file_exists($this->subFilePath)) {
            unlink($this->subFilePath);
        }
        if (is_dir($this->subDirPath)) {
            rmdir($this->subDirPath);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_resolves_local_direct_file(): void
    {
        $adapter = new StreamableStorageAdapter();
        $file = $adapter->resolve($this->testFilePath);

        $this->assertInstanceOf(StreamableFile::class, $file);
        $this->assertSame($this->testFilePath, $file->path);
        $this->assertSame(strlen('sample audio content'), $file->size);
        $this->assertNull($file->disk);
        $this->assertTrue($file->isLocal());

        $stream = $file->openStream();
        $this->assertIsResource($stream);
        $this->assertSame('sample audio content', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_resolves_via_fallback_directories_on_local(): void
    {
        // Set fallback to the temp directory + 'videos/'
        $adapter = (new StreamableStorageAdapter())
            ->withFallbackPrefixes([$this->tempDir . '/videos']);

        // Look for just 'intro.mp4' without the directory prefix
        $file = $adapter->resolve('intro.mp4');

        $this->assertSame($this->tempDir . '/videos/intro.mp4', $file->path);
        $this->assertSame(strlen('sample video content'), $file->size);

        $stream = $file->openStream();
        $this->assertSame('sample video content', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_resolves_via_custom_filesystem_mock(): void
    {
        $mockFs = $this->createMock(Filesystem::class);

        $mockFs->method('exists')
            ->willReturnCallback(fn (string $path) => $path === 'videos/nature.mp4');

        $mockFs->method('size')
            ->with('videos/nature.mp4')
            ->willReturn(1048576);

        $mockFs->method('lastModified')
            ->with('videos/nature.mp4')
            ->willReturn(1700000000);

        $mockStream = fopen('php://memory', 'w+b');
        fwrite($mockStream, 'simulated s3 video stream');
        rewind($mockStream);

        $mockFs->method('readStream')
            ->with('videos/nature.mp4')
            ->willReturn($mockStream);

        $adapter = (new StreamableStorageAdapter($mockFs))
            ->withFallbackPrefixes(['videos/', 'imports/']);

        // Requesting 'nature.mp4' should match via 'videos/' prefix
        $file = $adapter->resolve('nature.mp4');

        $this->assertSame('videos/nature.mp4', $file->path);
        $this->assertSame(1048576, $file->size);
        $this->assertSame(1700000000, $file->lastModified);
        $this->assertSame('custom', $file->disk);

        $stream = $file->openStream();
        $this->assertSame('simulated s3 video stream', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_throws_media_file_not_found_with_attempted_paths(): void
    {
        $adapter = (new StreamableStorageAdapter())
            ->withFallbackPrefixes(['videos/', 'imports/']);

        try {
            $adapter->resolve('non_existent.mp4');
            $this->fail('Expected MediaFileNotFoundException was not thrown');
        } catch (MediaFileNotFoundException $e) {
            $this->assertSame('non_existent.mp4', $e->path);
            $this->assertSame(['non_existent.mp4', 'videos/non_existent.mp4', 'imports/non_existent.mp4'], $e->attemptedPaths);

            $response = $e->toResponse();
            $this->assertSame(404, $response->getStatusCode());
        }
    }

    public function test_fluent_adapter_configuration_immutability(): void
    {
        $original = new StreamableStorageAdapter('public', ['videos/']);
        $modified = $original->withDisk('s3')->withFallbackPrefixes(['audio/', 'imports/'])->addFallbackPrefix('podcasts');

        $this->assertSame('public', $original->getDiskName());
        $this->assertSame(['videos/'], $original->getFallbackPrefixes());

        $this->assertSame('s3', $modified->getDiskName());
        $this->assertSame(['audio/', 'imports/', 'podcasts/'], $modified->getFallbackPrefixes());
    }
}

