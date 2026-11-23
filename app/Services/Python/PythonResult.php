<?php

declare(strict_types=1);

namespace App\Services\Python;

use JsonException;

class PythonResult
{
    /**
     * @param  array<int, string>  $command
     */
    public function __construct(
        protected int $exitCode,
        protected string $stdout,
        protected string $stderr = '',
        protected array $command = []
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return $this->exitCode !== 0;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function output(): string
    {
        return $this->stdout;
    }

    public function errorOutput(): string
    {
        return $this->stderr;
    }

    /**
     * @return array<int, string>
     */
    public function command(): array
    {
        return $this->command;
    }

    /**
     * Parse the stdout as JSON.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        try {
            $decoded = json_decode($this->stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }

        if ($key === null) {
            return $decoded;
        }

        return data_get($decoded, $key, $default);
    }

    /**
     * Parse the stdout as array.
     *
     * @return array<string|int, mixed>
     */
    public function array(): array
    {
        $data = $this->json();

        return is_array($data) ? $data : [];
    }
}

