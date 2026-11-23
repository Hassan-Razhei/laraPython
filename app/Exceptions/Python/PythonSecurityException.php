<?php

declare(strict_types=1);

namespace App\Exceptions\Python;

class PythonSecurityException extends PythonException
{
    public static function pathTraversalAttempt(string $path): self
    {
        return new self("Security violation: path traversal detected for script [{$path}].", [$path], 403, 'Path traversal detected');
    }

    public static function scriptNotFound(string $path): self
    {
        return new self("Python script file not found or unreadable at [{$path}].", [$path], 404, 'Script file not found');
    }

    public static function invalidExtension(string $path): self
    {
        return new self("Security violation: Python script must have a .py extension [{$path}].", [$path], 400, 'Invalid file extension');
    }
}

