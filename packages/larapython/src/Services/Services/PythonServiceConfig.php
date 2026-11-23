<?php

declare(strict_types=1);

namespace LaraPython\Services\Services;

class PythonServiceConfig
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly string $script,
        public readonly string $healthPath = '/health',
        public readonly int $timeout = 5,
        public readonly int $connectTimeout = 2,
        public readonly ?string $pidFile = null,
        public readonly array $options = []
    ) {
    }

    public function baseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    public function healthUrl(): string
    {
        return $this->baseUrl() . '/' . ltrim($this->healthPath, '/');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $key, array $config): self
    {
        return new self(
            key: $key,
            name: (string) ($config['name'] ?? $key),
            host: (string) ($config['host'] ?? '127.0.0.1'),
            port: (int) ($config['port'] ?? 8000),
            script: (string) ($config['script'] ?? ''),
            healthPath: (string) ($config['health_path'] ?? '/health'),
            timeout: (int) ($config['timeout'] ?? 5),
            connectTimeout: (int) ($config['connect_timeout'] ?? 2),
            pidFile: isset($config['pid_file']) ? (string) $config['pid_file'] : null,
            options: (array) ($config['options'] ?? [])
        );
    }
}

