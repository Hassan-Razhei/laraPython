<?php

declare(strict_types=1);

namespace EntityStreamer\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class MediaFileNotFoundException extends StreamResourceException
{
    /**
     * @param  string  $path  Target requested path
     * @param  string|null  $disk  Storage disk identifier
     * @param  array<int, string>  $attemptedPaths  All attempted candidate paths (including fallbacks)
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $disk = null,
        public readonly array $attemptedPaths = [],
        string $message = '',
        int $code = Response::HTTP_NOT_FOUND,
        ?\Throwable $previous = null
    ) {
        if ($message === '') {
            $diskInfo = $disk !== null ? " on disk [{$disk}]" : '';
            $attempted = ! empty($attemptedPaths) ? ' (Checked: '.implode(', ', $attemptedPaths).')' : '';
            $message = "Media file [{$path}] could not be found{$diskInfo}{$attempted}.";
        }

        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->getCode() ?: Response::HTTP_NOT_FOUND;
    }

    /**
     * Generate an HTTP 404 response.
     */
    public function toResponse(): Response
    {
        return new Response(
            content: 'Media file not found.',
            status: Response::HTTP_NOT_FOUND,
            headers: ['Content-Type' => 'text/plain']
        );
    }
}
