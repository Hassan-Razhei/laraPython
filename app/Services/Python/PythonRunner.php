<?php

declare(strict_types=1);

namespace App\Services\Python;

use App\Exceptions\Python\PythonExecutionException;
use App\Exceptions\Python\PythonNotFoundException;
use App\Exceptions\Python\PythonTimeoutException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PythonRunner
{
    public function __construct(
        protected PythonPathResolver $pathResolver
    ) {
    }

    /**
     * Run Python with the given arguments array.
     *
     * @param  array<int, string>  $arguments
     * @param  array<string, string>  $env
     *
     * @throws PythonNotFoundException
     * @throws PythonTimeoutException
     * @throws PythonExecutionException
     */
    public function run(
        array $arguments,
        int $timeout = 60,
        ?string $cwd = null,
        array $env = []
    ): PythonResult {
        $pythonBinary = $this->pathResolver->pythonBinary();

        if (! $this->pathResolver->isAvailable()) {
            $exception = PythonNotFoundException::forPath($pythonBinary);

            Log::error($exception->getMessage(), $exception->context());

            throw $exception;
        }

        $fullCommand = [$pythonBinary, ...$arguments];

        $process = Process::timeout($timeout)
            ->path($cwd ?? base_path())
            ->env($env);

        try {
            $result = $process->run($fullCommand);
        } catch (ProcessTimedOutException $e) {
            $exception = PythonTimeoutException::fromTimeout($fullCommand, $timeout, $e);

            Log::error($exception->getMessage(), $exception->context());

            throw $exception;
        }

        if ($result->failed()) {
            $exception = PythonExecutionException::fromProcessFailure(
                command: $fullCommand,
                exitCode: (int) $result->exitCode(),
                stderr: $result->errorOutput(),
                stdout: $result->output()
            );

            // Logging at error level triggers Boost's LastError listener and log watchers
            Log::error($exception->getMessage(), $exception->context());

            throw $exception;
        }

        return new PythonResult(
            exitCode: (int) $result->exitCode(),
            stdout: $result->output(),
            stderr: $result->errorOutput(),
            command: $fullCommand
        );
    }

    /**
     * Run inline Python code string with arguments.
     *
     * @param  array<int, string>  $args
     *
     * @throws PythonException
     */
    public function runInline(string $code, array $args = [], int $timeout = 60): PythonResult
    {
        return $this->run(['-c', $code, ...$args], $timeout);
    }

    /**
     * Run a Python script file with arguments.
     *
     * @param  array<int, string>  $args
     *
     * @throws PythonException
     */
    public function runScript(string $scriptPath, array $args = [], int $timeout = 60): PythonResult
    {
        return $this->run([$scriptPath, ...$args], $timeout);
    }
}
