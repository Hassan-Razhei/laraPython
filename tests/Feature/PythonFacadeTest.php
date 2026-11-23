<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Python\PythonSecurityException;
use App\Facades\Python;
use Tests\TestCase;

class PythonFacadeTest extends TestCase
{
    public function test_facade_executes_inline_code(): void
    {
        $result = Python::inline('import math; print(int(math.sqrt(144)))');

        $this->assertTrue($result->successful());
        $this->assertSame("12\n", $result->output());
    }

    public function test_facade_runs_script_with_arabic_payload_and_data_bridge(): void
    {
        $result = Python::run('analyze.py', [
            'text' => 'هذا مشروع رائع وممتاز جداً',
        ]);

        $this->assertTrue($result->successful());
        $this->assertSame('success', $result->json('status'));
        $this->assertSame('positive', $result->json('sentiment'));
        $this->assertSame('هذا مشروع رائع وممتاز جداً', $result->json('received_text'));
        $this->assertSame(5, $result->json('words_count'));
    }

    public function test_facade_blocks_path_traversal_attempts(): void
    {
        $this->expectException(PythonSecurityException::class);
        $this->expectExceptionMessage('path traversal detected');

        Python::run('../../secret.py');
    }

    public function test_facade_blocks_non_python_extensions(): void
    {
        $this->expectException(PythonSecurityException::class);
        $this->expectExceptionMessage('must have a .py extension');

        Python::run('malicious_script.sh');
    }

    public function test_facade_throws_exception_for_missing_script(): void
    {
        $this->expectException(PythonSecurityException::class);
        $this->expectExceptionMessage('not found or unreadable');

        Python::run('non_existent_file.py');
    }
}

