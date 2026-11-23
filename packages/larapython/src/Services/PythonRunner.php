<?php

declare(strict_types=1);

namespace LaraPython\Services;

use LaraPython\Contracts\PythonRunnerInterface;
use LaraPython\Exceptions\PythonExecutionException;
use LaraPython\Exceptions\PythonNotFoundException;
use LaraPython\Exceptions\PythonSecurityException;
use LaraPython\Exceptions\PythonTimeoutException;
use LaraPython\Jobs\ExecutePythonScriptJob;
use LaraPython\Services\Services\PythonServiceClient;
use LaraPython\Services\Services\PythonServiceManager;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use JsonException;

class PythonRunner implements PythonRunnerInterface
{
    public function __construct(
        protected PythonPathResolver $pathResolver,
        protected PythonScriptSecurity $scriptSecurity,
        protected ?PythonServiceManager $serviceManager = null
    ) {
    }

    /**
     * Run an allowlisted Python script with structured payload and arguments.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $args
     *
     * @throws PythonSecurityException
     * @throws PythonNotFoundException
     * @throws PythonTimeoutException
     * @throws PythonExecutionException
     */
    public function run(
        string $script,
        array $payload = [],
        array $args = [],
        ?int $timeout = null
    ): PythonResult {
        $safePath = $this->scriptSecurity->resolveSafeScriptPath($script);

        $payloadArgs = [];
        if ($payload !== []) {
            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $payloadArgs[] = '--payload-base64='.base64_encode($json);
            } catch (JsonException $e) {
                Log::warning("Failed to encode Python payload: {$e->getMessage()}", ['payload' => $payload]);
            }
        }

        $libPath = (string) config('python.lib_path', base_path('python/lib'));
        $existingPythonPath = (string) getenv('PYTHONPATH');
        $pythonPath = $existingPythonPath !== '' ? $libPath.PATH_SEPARATOR.$existingPythonPath : $libPath;

        $env = ['PYTHONPATH' => $pythonPath];

        return $this->raw(
            arguments: [$safePath, ...$payloadArgs, ...$args],
            timeout: $timeout,
            env: $env
        );
    }

    /**
     * Run inline Python code string with arguments.
     *
     * @param  array<int, string>  $args
     *
     * @throws PythonException
     */
    public function inline(string $code, array $args = [], ?int $timeout = null): PythonResult
    {
        return $this->raw(['-c', $code, ...$args], $timeout);
    }

    /**
     * Execute raw arguments directly via the resolved Python binary.
     *
     * @param  array<int, string>  $arguments
     * @param  array<string, string>  $env
     *
     * @throws PythonNotFoundException
     * @throws PythonTimeoutException
     * @throws PythonExecutionException
     */
    public function raw(
        array $arguments,
        ?int $timeout = null,
        ?string $cwd = null,
        array $env = []
    ): PythonResult {
        $pythonBinary = $this->pathResolver->pythonBinary();

        if (! $this->pathResolver->isAvailable()) {
            $exception = PythonNotFoundException::forPath($pythonBinary);

            Log::error($exception->getMessage(), $exception->context());

            throw $exception;
        }

        $effectiveTimeout = $timeout ?? (int) config('python.timeout', 60);
        $fullCommand = [$pythonBinary, ...$arguments];

        $process = Process::timeout($effectiveTimeout)
            ->path($cwd ?? base_path())
            ->env($env);

        try {
            $result = $process->run($fullCommand);
        } catch (ProcessTimedOutException $e) {
            $exception = PythonTimeoutException::fromTimeout($fullCommand, $effectiveTimeout, $e);

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

            // Logging at error level activates Boost's LastError listener and log watchers
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
     * Dispatch a Python script execution to the background queue.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $args
     */
    public function queue(
        string $script,
        array $payload = [],
        array $args = [],
        ?int $timeout = null
    ): PendingDispatch {
        return ExecutePythonScriptJob::dispatch(
            script: $script,
            payload: $payload,
            args: $args,
            timeout: $timeout ?? (int) config('python.timeout', 60)
        );
    }

    /**
     * Get an HTTP client instance for the specified Python continuous microservice.
     */
    public function service(string $name = 'ai'): PythonServiceClient
    {
        return $this->getServiceManager()->service($name);
    }

    /**
     * Get the service manager instance.
     */
    public function serviceManager(): PythonServiceManager
    {
        return $this->getServiceManager();
    }

    /**
     * Resolve the Python service manager instance.
     */
    protected function getServiceManager(): PythonServiceManager
    {
        return $this->serviceManager ??= app(PythonServiceManager::class);
    }

    /**
     * Alias for backward compatibility.
     *
     * @param  array<int, string>  $args
     */
    public function runInline(string $code, array $args = [], int $timeout = 60): PythonResult
    {
        return $this->inline($code, $args, $timeout);
    }

    /**
     * Alias for backward compatibility.
     *
     * @param  array<int, string>  $args
     */
    public function runScript(string $scriptPath, array $args = [], int $timeout = 60): PythonResult
    {
        return $this->raw([$scriptPath, ...$args], $timeout);
    }
}
