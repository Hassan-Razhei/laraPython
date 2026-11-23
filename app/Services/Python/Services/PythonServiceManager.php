<?php

declare(strict_types=1);

namespace App\Services\Python\Services;

use App\Services\Python\PythonPathResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

class PythonServiceManager
{
    /**
     * @var array<string, PythonServiceConfig>
     */
    protected array $services = [];

    public function __construct(
        protected readonly PythonPathResolver $pathResolver
    ) {
        $this->loadConfiguredServices();
    }

    /**
     * Get a client instance for the specified Python service.
     */
    public function service(string $name = 'ai'): PythonServiceClient
    {
        return new PythonServiceClient($this->getConfig($name));
    }

    /**
     * Check if a service is registered in configuration.
     */
    public function hasService(string $name): bool
    {
        return isset($this->services[$name]);
    }

    /**
     * Retrieve the configuration DTO for a service.
     */
    public function getConfig(string $name): PythonServiceConfig
    {
        if (! $this->hasService($name)) {
            throw new InvalidArgumentException("Python service [{$name}] is not configured in python.services.");
        }

        return $this->services[$name];
    }

    /**
     * Get all registered services.
     *
     * @return array<string, PythonServiceConfig>
     */
    public function allServices(): array
    {
        return $this->services;
    }

    /**
     * Check whether the service process is currently running.
     */
    public function isRunning(string $name): bool
    {
        $pid = $this->getPid($name);
        if ($pid === null) {
            return false;
        }

        $isAlive = $this->isProcessAlive($pid);

        if (! $isAlive) {
            $this->cleanPidFile($name);

            return false;
        }

        return true;
    }

    /**
     * Check whether the service health check passes.
     */
    public function isHealthy(string $name): bool
    {
        if (! $this->hasService($name)) {
            return false;
        }

        return $this->service($name)->healthCheck();
    }

    /**
     * Start the Python continuous microservice process.
     *
     * @return array{status: string, pid: ?int, healthy: bool, message: string}
     */
    public function start(string $name): array
    {
        $config = $this->getConfig($name);

        if ($this->isRunning($name)) {
            return [
                'status' => 'already_running',
                'pid' => $this->getPid($name),
                'healthy' => $this->isHealthy($name),
                'message' => "Service [{$name}] is already running.",
            ];
        }

        $python = $this->pathResolver->resolve();
        $scriptPath = base_path($config->script);

        if (! file_exists($scriptPath)) {
            throw new InvalidArgumentException("Service script file not found at [{$scriptPath}].");
        }

        if ($config->pidFile !== null) {
            $dir = dirname($config->pidFile);
            if (! is_dir($dir)) {
                File::makeDirectory($dir, 0755, true, true);
            }
        }

        $command = sprintf(
            'nohup %s %s --host %s --port %d > /dev/null 2>&1 & echo $!',
            escapeshellarg($python),
            escapeshellarg($scriptPath),
            escapeshellarg($config->host),
            $config->port
        );

        $result = Process::run($command);
        $pid = (int) trim($result->output());

        if ($pid > 0 && $config->pidFile !== null) {
            file_put_contents($config->pidFile, (string) $pid);
        }

        // Wait up to 2.5 seconds for service health check to pass
        $healthy = false;
        for ($i = 0; $i < 10; $i++) {
            usleep(250000); // 250ms
            if ($this->isHealthy($name)) {
                $healthy = true;
                break;
            }
        }

        return [
            'status' => 'started',
            'pid' => $pid > 0 ? $pid : null,
            'healthy' => $healthy,
            'message' => $healthy
                ? "Service [{$name}] started successfully on port {$config->port}."
                : "Service [{$name}] process started (PID: {$pid}), but health check has not passed yet.",
        ];
    }

    /**
     * Stop the Python microservice process.
     */
    public function stop(string $name): bool
    {
        $pid = $this->getPid($name);
        if ($pid === null) {
            $this->cleanPidFile($name);

            return false;
        }

        // Send SIGTERM (15)
        Process::run(['kill', '-15', (string) $pid]);

        // Poll for termination
        for ($i = 0; $i < 10; $i++) {
            usleep(100000); // 100ms
            if (! $this->isProcessAlive($pid)) {
                break;
            }
        }

        // Send SIGKILL (9) if still alive
        if ($this->isProcessAlive($pid)) {
            Process::run(['kill', '-9', (string) $pid]);
        }

        $this->cleanPidFile($name);

        return true;
    }

    /**
     * Retrieve status summary for a service.
     *
     * @return array{key: string, name: string, running: bool, healthy: bool, pid: ?int, url: string, port: int, script: string}
     */
    public function status(string $name): array
    {
        $config = $this->getConfig($name);
        $running = $this->isRunning($name);
        $healthy = $running && $this->isHealthy($name);

        return [
            'key' => $config->key,
            'name' => $config->name,
            'running' => $running,
            'healthy' => $healthy,
            'pid' => $running ? $this->getPid($name) : null,
            'url' => $config->baseUrl(),
            'port' => $config->port,
            'script' => $config->script,
        ];
    }

    /**
     * Get stored process PID if available.
     */
    public function getPid(string $name): ?int
    {
        $config = $this->getConfig($name);
        if ($config->pidFile === null || ! file_exists($config->pidFile)) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($config->pidFile));

        return $pid > 0 ? $pid : null;
    }

    /**
     * Check if a system process is alive.
     */
    protected function isProcessAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $result = Process::run(['kill', '-0', (string) $pid]);

        return $result->successful();
    }

    /**
     * Remove stale PID file if present.
     */
    protected function cleanPidFile(string $name): void
    {
        $config = $this->getConfig($name);
        if ($config->pidFile !== null && file_exists($config->pidFile)) {
            @unlink($config->pidFile);
        }
    }

    /**
     * Load services from configuration repository.
     */
    protected function loadConfiguredServices(): void
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = config('python.services', []);

        foreach ($configured as $key => $options) {
            $this->services[$key] = PythonServiceConfig::fromArray($key, $options);
        }
    }
}

