<?php

declare(strict_types=1);

namespace LaraPython\Exceptions;

use Throwable;

class PythonTimeoutException extends PythonException
{
    /**
     * Create an exception instance for a timed out process execution.
     *
     * @param  array<int, string>  $command
     */
    public static function fromTimeout(
        array $command,
        int $timeoutSeconds,
        ?Throwable $previous = null
    ): self {
        $message = "Python execution timed out after {$timeoutSeconds} seconds.";

        return new self($message, $command, 124, 'Execution timed out', '', $previous);
    }
}

