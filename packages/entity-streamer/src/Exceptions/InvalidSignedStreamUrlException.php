<?php

declare(strict_types=1);

namespace EntityStreamer\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class InvalidSignedStreamUrlException extends StreamResourceException
{
    public function __construct(
        public readonly string $reason = 'Invalid or expired media streaming signature.',
        int $code = Response::HTTP_FORBIDDEN,
        ?\Throwable $previous = null
    ) {
        parent::__construct("Security Violation: {$reason}", $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->getCode() ?: Response::HTTP_FORBIDDEN;
    }

    public function toResponse(): Response
    {
        return new Response(
            content: 'Access Denied: '.$this->reason,
            status: Response::HTTP_FORBIDDEN,
            headers: ['Content-Type' => 'text/plain']
        );
    }
}
