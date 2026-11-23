<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Python\PythonExecutionException;
use App\Exceptions\Python\PythonNotFoundException;
use App\Exceptions\Python\PythonTimeoutException;
use App\Services\Python\PythonRunner;
use Illuminate\Support\Facades\Cache;
use Laravel\Boost\Mcp\Tools\LastError;
use Laravel\Mcp\Request;
use Tests\TestCase;

class PythonErrorInterceptionTest extends TestCase
{
    public function test_successful_python_execution_returns_result(): void
    {
        $runner = app(PythonRunner::class);

        $result = $runner->runInline("print('Hello from Python')");

        $this->assertTrue($result->successful());
        $this->assertSame(0, $result->exitCode());
        $this->assertSame("Hello from Python\n", $result->output());
    }

    public function test_python_runner_parses_json_with_arabic_unicode(): void
    {
        $runner = app(PythonRunner::class);

        $script = "import json; print(json.dumps({'status': 'success', 'data': {'greeting': 'مرحبا بالعالم'}}))";
        $result = $runner->runInline($script);

        $this->assertTrue($result->successful());
        $this->assertSame('success', $result->json('status'));
        $this->assertSame('مرحبا بالعالم', $result->json('data.greeting'));
    }

    public function test_failed_python_execution_throws_exception_and_is_captured_by_boost_last_error(): void
    {
        /** @var LastError $lastErrorTool */
        $lastErrorTool = app(LastError::class);

        $runner = app(PythonRunner::class);

        try {
            $runner->runInline("import sys; sys.stderr.write('Traceback (most recent call last): DivisionByZero'); sys.exit(2)");
            $this->fail('Expected PythonExecutionException was not thrown.');
        } catch (PythonExecutionException $e) {
            $this->assertSame(2, $e->getExitCode());
            $this->assertStringContainsString('DivisionByZero', $e->getStderr());

            $cachedError = Cache::get('boost:last_error');
            $this->assertIsArray($cachedError);
            $this->assertSame('error', $cachedError['level']);
            $this->assertStringContainsString('DivisionByZero', $cachedError['message']);

            $response = $lastErrorTool->handle(new Request());
            $this->assertFalse($response->isError());
            $this->assertStringContainsString('DivisionByZero', (string) $response->content());
        }
    }

    public function test_timed_out_python_execution_throws_exception_and_is_captured_by_boost(): void
    {
        /** @var LastError $lastErrorTool */
        $lastErrorTool = app(LastError::class);

        $runner = app(PythonRunner::class);

        try {
            $runner->runInline('import time; time.sleep(5)', timeout: 1);
            $this->fail('Expected PythonTimeoutException was not thrown.');
        } catch (PythonTimeoutException $e) {
            $this->assertSame(124, $e->getExitCode());
            $this->assertStringContainsString('timed out', $e->getMessage());

            $cachedError = Cache::get('boost:last_error');
            $this->assertIsArray($cachedError);
            $this->assertSame('error', $cachedError['level']);
            $this->assertStringContainsString('timed out', $cachedError['message']);
        }
    }

    public function test_missing_python_binary_throws_python_not_found_exception(): void
    {
        config(['python.executable_paths.python' => '/invalid/path/to/missing_python_binary']);

        $runner = app(PythonRunner::class);

        $this->expectException(PythonNotFoundException::class);
        $this->expectExceptionCode(127);

        $runner->runInline("print('should fail')");
    }
}
