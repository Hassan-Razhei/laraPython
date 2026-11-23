<?php

declare(strict_types=1);

namespace LaraPython\Services;

use LaraPython\Exceptions\PythonSecurityException;

class PythonScriptSecurity
{
    /**
     * Resolve and validate that a script path is safe and within the allowed scripts directory.
     *
     * @throws PythonSecurityException
     */
    public function resolveSafeScriptPath(string $script): string
    {
        // 1. Block null byte injection
        if (str_contains($script, "\0")) {
            throw PythonSecurityException::pathTraversalAttempt($script);
        }

        // 2. Block path traversal tokens
        if (str_contains($script, '..')) {
            throw PythonSecurityException::pathTraversalAttempt($script);
        }

        // 3. Ensure valid .py extension
        if (! str_ends_with(strtolower($script), '.py')) {
            throw PythonSecurityException::invalidExtension($script);
        }

        $baseDir = $this->scriptsDirectory();

        // 4. Resolve candidate path
        $candidate = str_starts_with($script, DIRECTORY_SEPARATOR)
            ? $script
            : $baseDir.DIRECTORY_SEPARATOR.$script;

        $realBase = realpath($baseDir);
        $realPath = realpath($candidate);

        // 5. Verify file existence and readability
        if ($realPath === false || ! file_exists($realPath) || is_dir($realPath)) {
            throw PythonSecurityException::scriptNotFound($script);
        }

        // 6. Enforce that real path is within the allowed base directory
        if ($realBase === false || ! str_starts_with($realPath, $realBase)) {
            throw PythonSecurityException::pathTraversalAttempt($script);
        }

        return $realPath;
    }

    /**
     * Get the configured allowed scripts directory.
     */
    public function scriptsDirectory(): string
    {
        return (string) config('python.scripts_path', base_path('python/scripts'));
    }
}

