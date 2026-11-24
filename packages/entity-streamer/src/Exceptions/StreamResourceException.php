<?php

declare(strict_types=1);

namespace EntityStreamer\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StreamResourceException extends RuntimeException
{
    public function getStatusCode(): int
    {
        return $this->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    public static function unableToOpenFile(string $path, ?string $reason = null): self
    {
        $message = "Unable to open media file for streaming: [{$path}].";
        if ($reason !== null) {
            $message .= " Reason: {$reason}";
        }

        return new self($message);
    }

    public static function invalidResource(): self
    {
        return new self('Expected a valid stream resource or readable file path.');
    }

    public static function seekFailed(int $offset): self
    {
        return new self("Failed to seek to byte offset {$offset} in media stream.");
    }
}
