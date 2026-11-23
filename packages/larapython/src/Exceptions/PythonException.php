<?php

declare(strict_types=1);

namespace LaraPython\Exceptions;

use RuntimeException;
use Throwable;

abstract class PythonException extends RuntimeException
{
    /**
     * @param  array<int, string>  $command
     */
    public function __construct(
        string $message,
        protected array $command = [],
        protected int $exitCode = 1,
        protected string $stderr = '',
        protected string $stdout = '',
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $exitCode, $previous);
    }

    /**
     * Attach structured data to the exception log context.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'command' => $this->command,
            'exit_code' => $this->exitCode,
            'stderr' => $this->stderr,
            'stdout' => $this->stdout,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getCommand(): array
    {
        return $this->command;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    public function getStdout(): string
    {
        return $this->stdout;
    }
}

