<?php

declare(strict_types=1);

namespace EntityStreamer\Tests\Unit;

use EntityStreamer\Exceptions\PathTraversalException;
use EntityStreamer\Security\PathSanitizer;
use PHPUnit\Framework\TestCase;

class PathSanitizerTest extends TestCase
{
    private PathSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new PathSanitizer();
    }

    public function test_allows_valid_clean_paths(): void
    {
        $this->assertSame('videos/lecture1.mp4', $this->sanitizer->sanitize('videos/lecture1.mp4'));
        $this->assertSame('/audio/recitation.mp3', $this->sanitizer->sanitize('/audio/recitation.mp3'));
        $this->assertSame('deep/nested/storage/manuscript.wav', $this->sanitizer->sanitize('deep//nested/storage/manuscript.wav'));
        $this->assertTrue($this->sanitizer->isSafe('videos/lecture.mp4'));
    }

    public function test_blocks_classic_directory_traversal(): void
    {
        $this->expectException(PathTraversalException::class);
        $this->sanitizer->sanitize('../secret.mp4');
    }

    public function test_blocks_nested_directory_traversal(): void
    {
        $this->expectException(PathTraversalException::class);
        $this->sanitizer->sanitize('videos/../../etc/passwd');
    }

    public function test_blocks_windows_style_directory_traversal(): void
    {
        $this->expectException(PathTraversalException::class);
        $this->sanitizer->sanitize('videos\\..\\..\\windows\\system32');
    }

    public function test_blocks_url_encoded_traversal(): void
    {
        $this->expectException(PathTraversalException::class);
        $this->sanitizer->sanitize('%2e%2e/config.php');
    }

    public function test_blocks_double_url_encoded_traversal(): void
    {
        $this->expectException(PathTraversalException::class);
        $this->sanitizer->sanitize('%252e%252e/admin/video.mp4');
    }

    public function test_blocks_null_byte_injection(): void
    {
        $this->expectException(PathTraversalException::class);
        $this->sanitizer->sanitize("video.mp4\0.php");
    }

    public function test_blocks_system_filenames(): void
    {
        $blocked = ['.env', '.git', '.htaccess', 'composer.json'];

        foreach ($blocked as $file) {
            $this->assertFalse($this->sanitizer->isSafe($file), "Expected [{$file}] to be blocked");
            $this->assertFalse($this->sanitizer->isSafe("sub/{$file}"), "Expected [sub/{$file}] to be blocked");
        }
    }

    public function test_blocks_windows_device_names(): void
    {
        $this->assertFalse($this->sanitizer->isSafe('CON.mp4'));
        $this->assertFalse($this->sanitizer->isSafe('NUL.mp3'));
        $this->assertFalse($this->sanitizer->isSafe('AUX.wav'));
    }

    public function test_exception_generates_http_403_response(): void
    {
        try {
            $this->sanitizer->sanitize('../bad.mp4');
            $this->fail('Expected PathTraversalException');
        } catch (PathTraversalException $e) {
            $response = $e->toResponse();
            $this->assertSame(403, $response->getStatusCode());
        }
    }
}

