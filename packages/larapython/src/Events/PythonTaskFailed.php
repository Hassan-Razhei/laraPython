<?php

declare(strict_types=1);

namespace LaraPython\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PythonTaskFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $script,
        public readonly array $payload,
        public readonly ?Throwable $exception,
        public readonly ?string $jobId = null
    ) {
    }
}

