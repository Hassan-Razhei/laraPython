<?php

declare(strict_types=1);

namespace LaraPython\Jobs;

use LaraPython\Contracts\PythonRunnerInterface;
use LaraPython\Events\PythonTaskCompleted;
use LaraPython\Events\PythonTaskFailed;
use LaraPython\Events\PythonTaskStarted;
use LaraPython\Services\PythonResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecutePythonScriptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $args
     */
    public function __construct(
        public string $script,
        public array $payload = [],
        public array $args = [],
        public int $timeout = 120
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(PythonRunnerInterface $runner): PythonResult
    {
        $jobId = $this->job?->getJobId();

        event(new PythonTaskStarted(
            script: $this->script,
            payload: $this->payload,
            jobId: $jobId
        ));

        try {
            $result = $runner->run(
                script: $this->script,
                payload: $this->payload,
                args: $this->args,
                timeout: $this->timeout
            );

            event(new PythonTaskCompleted(
                script: $this->script,
                payload: $this->payload,
                result: $result,
                jobId: $jobId
            ));

            return $result;
        } catch (Throwable $e) {
            event(new PythonTaskFailed(
                script: $this->script,
                payload: $this->payload,
                exception: $e,
                jobId: $jobId
            ));

            throw $e;
        }
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("Queued Python job permanently failed for script [{$this->script}]", [
            'script' => $this->script,
            'payload' => $this->payload,
            'job_id' => $this->job?->getJobId(),
            'error' => $exception?->getMessage(),
        ]);
    }
}

