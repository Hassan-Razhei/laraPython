<?php

declare(strict_types=1);

namespace EntityStreamer\Storage;

use EntityStreamer\Exceptions\MediaFileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Storage Driver Agnostic Adapter.
 *
 * Resolves media assets across local and cloud storage drivers (S3, R2, MinIO, Wasabi),
 * supporting automatic fallback directories (e.g. 'videos/', 'imports/').
 */
class StreamableStorageAdapter
{
    protected ?string $diskName = null;
    protected ?Filesystem $filesystem = null;

    /** @var array<int, string> */
    protected array $fallbackPrefixes = [];

    /**
     * @param string|Filesystem|null $disk Disk name or Filesystem instance
     * @param array<int, string> $fallbackPrefixes List of fallback directory prefixes
     */
    public function __construct(
        string|Filesystem|null $disk = null,
        array $fallbackPrefixes = []
    ) {
        if ($disk instanceof Filesystem) {
            $this->filesystem = $disk;
            $this->diskName = 'custom';
        } elseif (is_string($disk)) {
            $this->diskName = $disk;
        }

        $this->setFallbackPrefixes($fallbackPrefixes);
    }

    /**
     * Set target storage disk by name or Filesystem instance.
     */
    public function withDisk(string|Filesystem|null $disk): self
    {
        $clone = clone $this;
        if ($disk instanceof Filesystem) {
            $clone->filesystem = $disk;
            $clone->diskName = 'custom';
        } else {
            $clone->filesystem = null;
            $clone->diskName = $disk;
        }
        return $clone;
    }

    /**
     * Set fallback directory prefixes to search when a file is not in root path.
     *
     * @param array<int, string> $prefixes
     */
    public function withFallbackPrefixes(array $prefixes): self
    {
        $clone = clone $this;
        $clone->setFallbackPrefixes($prefixes);
        return $clone;
    }

    /**
     * Add a single fallback directory prefix (e.g. 'videos/').
     */
    public function addFallbackPrefix(string $prefix): self
    {
        $clone = clone $this;
        $normalized = rtrim(ltrim($prefix, '/'), '/') . '/';
        if (!in_array($normalized, $clone->fallbackPrefixes, true)) {
            $clone->fallbackPrefixes[] = $normalized;
        }
        return $clone;
    }

    public function getDiskName(): ?string
    {
        return $this->diskName;
    }

    /**
     * @return array<int, string>
     */
    public function getFallbackPrefixes(): array
    {
        return $this->fallbackPrefixes;
    }

    /**
     * Resolve a path into a stream-ready StreamableFile.
     *
     * @param string $path File path or relative storage path
     * @return StreamableFile
     *
     * @throws MediaFileNotFoundException
     */
    public function resolve(string $path): StreamableFile
    {
        $candidates = $this->buildCandidatePaths($path);

        // Path resolution via Laravel Storage Disk / Filesystem
        $filesystem = $this->getFilesystemInstance();

        if ($filesystem !== null) {
            foreach ($candidates as $candidate) {
                // Storage disk paths are usually relative
                $diskCandidate = ltrim($candidate, '/');
                if ($filesystem->exists($diskCandidate)) {
                    $size = $filesystem->size($diskCandidate);
                    $lastModified = $filesystem->lastModified($diskCandidate);

                    $mimeType = null;
                    if (method_exists($filesystem, 'mimeType')) {
                        $mimeType = $filesystem->mimeType($diskCandidate) ?: null;
                    }

                    $streamOpener = fn () => $filesystem->readStream($diskCandidate);

                    return new StreamableFile(
                        path: $diskCandidate,
                        size: $size,
                        lastModified: $lastModified,
                        disk: $this->diskName,
                        mimeType: $mimeType,
                        stream: $streamOpener
                    );
                }
            }
        } else {
            // Path resolution via direct local filesystem
            foreach ($candidates as $candidate) {
                if (file_exists($candidate) && !is_dir($candidate) && is_readable($candidate)) {
                    $size = (int) filesize($candidate);
                    $lastModified = (int) filemtime($candidate);
                    $mimeType = @mime_content_type($candidate) ?: null;

                    return new StreamableFile(
                        path: $candidate,
                        size: $size,
                        lastModified: $lastModified,
                        disk: null,
                        mimeType: $mimeType
                    );
                }
            }
        }

        throw new MediaFileNotFoundException(
            path: $path,
            disk: $this->diskName,
            attemptedPaths: $candidates
        );
    }

    /**
     * Obtain active Filesystem instance if a disk is configured.
     */
    protected function getFilesystemInstance(): ?Filesystem
    {
        if ($this->filesystem !== null) {
            return $this->filesystem;
        }

        if ($this->diskName !== null) {
            return Storage::disk($this->diskName);
        }

        return null;
    }

    /**
     * Generate list of candidate paths combining the direct path and all fallback prefixes.
     *
     * @return array<int, string>
     */
    protected function buildCandidatePaths(string $path): array
    {
        $candidates = [$path];
        $trimmed = ltrim($path, '/');
        if ($trimmed !== '' && $trimmed !== $path) {
            $candidates[] = $trimmed;
        }

        foreach ($this->fallbackPrefixes as $prefix) {
            $candidate = $prefix . $trimmed;
            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param array<int, string> $prefixes
     */
    protected function setFallbackPrefixes(array $prefixes): void
    {
        $this->fallbackPrefixes = [];
        foreach ($prefixes as $prefix) {
            $isAbsolute = str_starts_with($prefix, '/');
            $clean = trim($prefix, '/');
            if ($clean !== '') {
                $this->fallbackPrefixes[] = ($isAbsolute ? '/' : '') . $clean . '/';
            }
        }
    }
}
