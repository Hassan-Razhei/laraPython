<?php

declare(strict_types=1);

namespace EntityStreamer\Security;

use EntityStreamer\Exceptions\PathTraversalException;

/**
 * Validates and cleanses media paths against directory traversal,
 * null-byte poisoning, and unauthorized system access.
 */
class PathSanitizer
{
    /** Disallowed hidden/system filenames */
    public const BLOCKED_FILENAMES = [
        '.env',
        '.env.example',
        '.git',
        '.gitignore',
        '.htaccess',
        'web.config',
        'composer.json',
        'composer.lock',
    ];

    /** Prohibited Windows device names */
    public const WINDOWS_DEVICES = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    /**
     * Sanitize and normalize a path, throwing an exception on malicious patterns.
     *
     * @param  string  $path  Raw user-supplied path
     * @return string Normalized relative path
     *
     * @throws PathTraversalException
     */
    public function sanitize(string $path): string
    {
        // 1. Check for null byte injection (binary, URL-encoded, or escaped)
        if (str_contains($path, "\0") || str_contains($path, '%00') || str_contains($path, '\0') || str_contains($path, '\x00')) {
            throw new PathTraversalException($path, 'Null byte detected in path.');
        }

        // 2. Decode URL encoding iteratively to counter multi-layer encoding (e.g. %252e%252e)
        $decoded = $path;
        for ($i = 0; $i < 3; $i++) {
            $prev = $decoded;
            $decoded = rawurldecode($decoded);
            if ($decoded === $prev) {
                break;
            }
        }

        // 3. Normalize directory separators
        $normalized = str_replace('\\', '/', $decoded);
        $isAbsolute = str_starts_with($normalized, '/');

        // 4. Split path into segments and validate
        $segments = explode('/', $normalized);
        $safeSegments = [];

        foreach ($segments as $segment) {
            $trimmedSegment = trim($segment);

            // Skip empty segments created by multiple slashes
            if ($trimmedSegment === '' || $trimmedSegment === '.') {
                continue;
            }

            // Directory traversal check
            if ($trimmedSegment === '..' || str_contains($trimmedSegment, '..')) {
                throw new PathTraversalException($path, 'Directory climbing (..) is prohibited.');
            }

            // Prohibit access to sensitive dotfiles
            if (in_array(strtolower($trimmedSegment), self::BLOCKED_FILENAMES, true)) {
                throw new PathTraversalException($path, "Access to system file [{$trimmedSegment}] is prohibited.");
            }

            // Prohibit Windows device names
            $baseNameWithoutExt = strtoupper(pathinfo($trimmedSegment, PATHINFO_FILENAME));
            if (in_array($baseNameWithoutExt, self::WINDOWS_DEVICES, true)) {
                throw new PathTraversalException($path, "Windows device name [{$trimmedSegment}] is prohibited.");
            }

            $safeSegments[] = $trimmedSegment;
        }

        if (empty($safeSegments)) {
            throw new PathTraversalException($path, 'Path cannot resolve to empty root.');
        }

        $result = implode('/', $safeSegments);

        return $isAbsolute ? '/'.$result : $result;
    }

    /**
     * Check if a path is clean without throwing an exception.
     */
    public function isSafe(string $path): bool
    {
        try {
            $this->sanitize($path);

            return true;
        } catch (PathTraversalException) {
            return false;
        }
    }
}
