<?php

declare(strict_types=1);

namespace EntityStreamer\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class InvalidByteRangeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $totalSize,
        public readonly ?string $rawHeader = null,
        int $code = Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->getCode() ?: Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE;
    }

    /**
     * Get the standard RFC 7233 Content-Range header value for 416 response.
     */
    public function toContentRangeHeader(): string
    {
        return sprintf('bytes */%d', $this->totalSize);
    }

    /**
     * Generate a complete RFC 7233 compliant HTTP 416 Response.
     */
    public function toResponse(): Response
    {
        return new Response(
            content: '',
            status: Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE,
            headers: [
                'Content-Range' => $this->toContentRangeHeader(),
                'Accept-Ranges' => 'bytes',
                'Content-Length' => '0',
            ]
        );
    }
}
