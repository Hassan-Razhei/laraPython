<?php

declare(strict_types=1);

namespace App\Exceptions\Python;

use Throwable;

class PythonNotFoundException extends PythonException
{
    /**
     * Create an exception instance when python executable is missing.
     */
    public static function forPath(string $path, ?Throwable $previous = null): self
    {
        $message = "Python executable not found at [{$path}].";

        return new self($message, [$path], 127, 'Executable not found', '', $previous);
    }
}

