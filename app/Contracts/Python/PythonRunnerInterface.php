<?php

declare(strict_types=1);

namespace App\Contracts\Python;

use App\Services\Python\PythonResult;

interface PythonRunnerInterface
{
    /**
     * Run an allowlisted Python script with structured payload and arguments.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $args
     */
    public function run(string $script, array $payload = [], array $args = [], ?int $timeout = null): PythonResult;

    /**
     * Run inline Python code string with arguments.
     *
     * @param  array<int, string>  $args
     */
    public function inline(string $code, array $args = [], ?int $timeout = null): PythonResult;

    /**
     * Execute raw arguments directly via the resolved Python binary.
     *
     * @param  array<int, string>  $arguments
     * @param  array<string, string>  $env
     */
    public function raw(array $arguments, ?int $timeout = null, ?string $cwd = null, array $env = []): PythonResult;
}

