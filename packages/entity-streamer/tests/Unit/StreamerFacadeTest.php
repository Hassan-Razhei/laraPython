<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Storage\StreamableFile;
use EntityStreamer\StreamerBuilder;
use EntityStreamer\StreamerManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class StreamerFacadeTest extends TestCase
{
    private string $tempFilePath;

    private string $sampleContent;

    private StreamerManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleContent = str_repeat('Testing Streamer Manager & Facade Integration! ', 150);
        $this->tempFilePath = tempnam(sys_get_temp_dir(), 'facade_test_');
        file_put_contents($this->tempFilePath, $this->sampleContent);

        $this->manager = new StreamerManager([
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
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFilePath)) {
            unlink($this->tempFilePath);
        }
        parent::tearDown();
    }

    public function test_manager_returns_fluent_builder(): void
    {
        $builder = $this->manager->stream($this->tempFilePath);
        $this->assertInstanceOf(StreamerBuilder::class, $builder);

        $file = $builder->resolveFile();
        $this->assertInstanceOf(StreamableFile::class, $file);
        $this->assertSame(strlen($this->sampleContent), $file->size);
    }

    public function test_fluent_builder_to_response(): void
    {
        $request = Request::create('/stream/media', 'GET', [], [], [], [
            'HTTP_RANGE' => 'bytes=100-300',
        ]);

        $response = $this->manager->stream($this->tempFilePath)
            ->withChunkSize(4096)
            ->asInline('custom_lecture.mp4')
            ->toResponse($request);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('201', $response->headers->get('Content-Length'));
        $this->assertStringStartsWith('bytes 100-300/', (string) $response->headers->get('Content-Range'));
        $this->assertStringContainsString('custom_lecture.mp4', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_manager_signs_and_validates_urls(): void
    {
        $path = 'videos/quran-recitation.mp4';
        $signedUrl = $this->manager->sign($path, expiration: 1800);

        $this->assertStringContainsString('signature=', $signedUrl);
        $this->assertStringContainsString('expires=', $signedUrl);

        parse_str(parse_url($signedUrl, PHP_URL_QUERY), $query);
        $request = Request::create($signedUrl, 'GET', $query);

        $isValid = $this->manager->validateSignature($request, $path);
        $this->assertTrue($isValid);
    }

    public function test_attachment_mode_formats_content_disposition(): void
    {
        $request = Request::create('/download', 'GET');

        $response = $this->manager->stream($this->tempFilePath)
            ->asAttachment('lecture_download.mp4')
            ->toResponse($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('lecture_download.mp4', (string) $response->headers->get('Content-Disposition'));
    }
}
