<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Contracts\CanStreamMedia;
use EntityStreamer\Exceptions\PathTraversalException;
use EntityStreamer\Exceptions\StreamResourceException;
use EntityStreamer\Storage\StreamableFile;
use EntityStreamer\Traits\HasStreamableMedia;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

// Dummy Eloquent-like model for testing
class SampleVideoModel implements CanStreamMedia
{
    use HasStreamableMedia;

    public function __construct(public array $attributes = [])
    {
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}

// Multi-channel model (e.g. Book with audio narration and trailer)
class SampleMultiMediaModel implements CanStreamMedia
{
    use HasStreamableMedia;

    protected array $streamableMedia = [
        'default' => 'video_path',
        'trailer' => 'trailer_path',
        'audio'   => 'audio_path',
    ];

    public function __construct(public array $attributes = [])
    {
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}

class HasStreamableMediaTest extends TestCase
{
    private string $tempFilePath;
    private string $sampleContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sampleContent = str_repeat('Testing 123 Eloquent Streaming Content! ', 200);
        $this->tempFilePath = tempnam(sys_get_temp_dir(), 'eloquent_stream_');
        file_put_contents($this->tempFilePath, $this->sampleContent);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFilePath)) {
            unlink($this->tempFilePath);
        }
        parent::tearDown();
    }

    public function test_model_discovers_default_file_path(): void
    {
        $model = new SampleVideoModel(['file_path' => $this->tempFilePath]);

        $this->assertSame($this->tempFilePath, $model->getStreamPath());
        $file = $model->toStreamableFile();

        $this->assertInstanceOf(StreamableFile::class, $file);
        $this->assertSame(strlen($this->sampleContent), $file->size);
    }

    public function test_model_supports_multi_channel_media(): void
    {
        $trailerPath = tempnam(sys_get_temp_dir(), 'trailer_');
        file_put_contents($trailerPath, 'trailer content');

        $model = new SampleMultiMediaModel([
            'video_path'   => $this->tempFilePath,
            'trailer_path' => $trailerPath,
        ]);

        $this->assertSame($this->tempFilePath, $model->getStreamPath('default'));
        $this->assertSame($trailerPath, $model->getStreamPath('trailer'));

        unlink($trailerPath);
    }

    public function test_throws_exception_when_no_media_column_is_found(): void
    {
        $model = new SampleVideoModel(['title' => 'Sample Book Without Media']);

        $this->expectException(StreamResourceException::class);
        $model->getStreamPath();
    }

    public function test_blocks_path_traversal_attacks_in_model_attributes(): void
    {
        $model = new SampleVideoModel(['file_path' => '../secret/passwords.txt']);

        $this->expectException(PathTraversalException::class);
        $model->toStreamableFile();
    }

    public function test_generates_signed_streaming_url(): void
    {
        $model = new SampleVideoModel(['file_path' => 'videos/lesson.mp4']);
        $url = $model->getStreamUrl(expiration: 3600);

        $this->assertStringContainsString('/stream/videos/lesson.mp4', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_streams_rfc7233_response_directly_from_model(): void
    {
        $model = new SampleVideoModel(['file_path' => $this->tempFilePath]);

        // Range request for bytes 0-100
        $request = Request::create('/stream/video', 'GET', [], [], [], [
            'HTTP_RANGE' => 'bytes=0-100',
        ]);

        $response = $model->streamResponse($request);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('101', $response->headers->get('Content-Length'));
        $this->assertStringStartsWith('bytes 0-100/', (string) $response->headers->get('Content-Range'));
    }

    public function test_returns_304_not_modified_from_model_on_matching_etag(): void
    {
        $model = new SampleVideoModel(['file_path' => $this->tempFilePath]);
        $file = $model->toStreamableFile();
        $etag = sprintf('W/"%x-%x"', $file->size, $file->lastModified);

        $request = Request::create('/stream/video', 'GET', [], [], [], [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);

        $response = $model->streamResponse($request);
        $this->assertSame(304, $response->getStatusCode());
    }
}

