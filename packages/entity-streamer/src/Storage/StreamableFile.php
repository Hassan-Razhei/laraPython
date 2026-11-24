<?php

declare(strict_types=1);

namespace EntityStreamer\Storage;

use EntityStreamer\Exceptions\StreamResourceException;

/**
 * Describes a resolved, stream-ready media asset regardless of storage backend.
 */
class StreamableFile
{
    /** @var (callable(): resource)|null */
    protected $streamOpener = null;

    /**
     * @param string $path The resolved path or storage relative path
     * @param int $size Total file size in bytes
     * @param int $lastModified UNIX timestamp of last modification
     * @param string|null $disk Storage disk identifier ('public', 's3', etc.)
     * @param string|null $mimeType Detected or explicit MIME type
     * @param (callable(): resource)|resource|null $stream Stream resource or lazy opener callback
     */
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly int $lastModified,
        public readonly ?string $disk = null,
        public readonly ?string $mimeType = null,
        mixed $stream = null
    ) {
        if (is_callable($stream)) {
            $this->streamOpener = $stream;
        } elseif (is_resource($stream)) {
            $this->streamOpener = static fn () => $stream;
        }
    }

    /**
     * Open and return a readable stream resource for this media asset.
     *
     * @return resource
     * @throws StreamResourceException
     */
    public function openStream()
    {
        if ($this->streamOpener !== null) {
            $resource = ($this->streamOpener)();
            if (is_resource($resource) && get_resource_type($resource) === 'stream') {
                return $resource;
            }
        }

        if (file_exists($this->path) && is_readable($this->path)) {
            $handle = @fopen($this->path, 'rb');
            if ($handle !== false) {
                return $handle;
            }
        }

        throw StreamResourceException::unableToOpenFile($this->path, 'Could not obtain readable stream handle');
    }

    public function isLocal(): bool
    {
        return $this->disk === null || $this->disk === 'local' || $this->disk === 'public';
    }

    public function getExtension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }
}

