<?php

declare(strict_types=1);

namespace EntityStreamer\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class PathTraversalException extends StreamResourceException
{
    public function __construct(
        public readonly string $maliciousPath,
        string $message = '',
        int $code = Response::HTTP_FORBIDDEN,
        ?\Throwable $previous = null
    ) {
        if ($message === '') {
            $message = "Security Violation: Potential path traversal attack detected in [{$maliciousPath}].";
        }

        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->getCode() ?: Response::HTTP_FORBIDDEN;
    }

    public function toResponse(): Response
    {
        return new Response(
            content: 'Access Denied: Invalid media path.',
            status: Response::HTTP_FORBIDDEN,
            headers: ['Content-Type' => 'text/plain']
        );
    }
}
