<?php

declare(strict_types=1);

namespace App\Exceptions\Python;

use Throwable;

class PythonExecutionException extends PythonException
{
    /**
     * Create an exception instance from a failed process execution.
     *
     * @param  array<int, string>  $command
     */
    public static function fromProcessFailure(
        array $command,
        int $exitCode,
        string $stderr,
        string $stdout = '',
        ?Throwable $previous = null
    ): self {
        $cleanStderr = trim($stderr);
        $summary = $cleanStderr !== '' ? $cleanStderr : 'Process failed without stderr output.';
        $message = "Python execution failed [exit code {$exitCode}]: {$summary}";

        return new self($message, $command, $exitCode, $stderr, $stdout, $previous);
    }
}

