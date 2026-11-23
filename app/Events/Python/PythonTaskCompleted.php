<?php

declare(strict_types=1);

namespace App\Events\Python;

use App\Services\Python\PythonResult;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PythonTaskCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $script,
        public readonly array $payload,
        public readonly PythonResult $result,
        public readonly ?string $jobId = null
    ) {
    }
}

