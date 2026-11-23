<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\Python\PythonTaskCompleted;
use App\Events\Python\PythonTaskFailed;
use App\Events\Python\PythonTaskStarted;
use App\Exceptions\Python\PythonSecurityException;
use App\Facades\Python;
use App\Jobs\ExecutePythonScriptJob;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

class PythonQueueIntegrationTest extends TestCase
{
    public function test_facade_dispatches_job_to_queue(): void
    {
        Queue::fake();

        $dispatch = Python::queue('analyze.py', ['text' => 'مرحبا بك في معالجة الطوابير']);

        $this->assertInstanceOf(\Illuminate\Foundation\Bus\PendingDispatch::class, $dispatch);

        Queue::assertPushed(ExecutePythonScriptJob::class, function (ExecutePythonScriptJob $job): bool {
            return $job->script === 'analyze.py'
                && $job->payload === ['text' => 'مرحبا بك في معالجة الطوابير'];
        });
    }

    public function test_facade_supports_queue_chaining_options(): void
    {
        Queue::fake();

        Python::queue('analyze.py', ['priority' => 'high'])
            ->onQueue('ai-tasks')
            ->delay(60);

        Queue::assertPushedOn('ai-tasks', ExecutePythonScriptJob::class, function (ExecutePythonScriptJob $job): bool {
            return $job->script === 'analyze.py'
                && $job->payload === ['priority' => 'high']
                && $job->delay === 60;
        });
    }

    public function test_job_execution_dispatches_started_and_completed_events(): void
    {
        Event::fake([
            PythonTaskStarted::class,
            PythonTaskCompleted::class,
            PythonTaskFailed::class,
        ]);

        ExecutePythonScriptJob::dispatchSync('analyze.py', ['text' => 'تجربة نجاح الحدث']);

        Event::assertDispatched(PythonTaskStarted::class, function (PythonTaskStarted $event): bool {
            return $event->script === 'analyze.py'
                && $event->payload === ['text' => 'تجربة نجاح الحدث'];
        });

        Event::assertDispatched(PythonTaskCompleted::class, function (PythonTaskCompleted $event): bool {
            return $event->script === 'analyze.py'
                && $event->payload === ['text' => 'تجربة نجاح الحدث']
                && $event->result->successful()
                && $event->result->json('status') === 'success';
        });

        Event::assertNotDispatched(PythonTaskFailed::class);
    }

    public function test_job_execution_dispatches_failed_event_on_error(): void
    {
        Event::fake([
            PythonTaskStarted::class,
            PythonTaskCompleted::class,
            PythonTaskFailed::class,
        ]);

        try {
            ExecutePythonScriptJob::dispatchSync('non_existent.py', ['param' => 1]);
            $this->fail('Expected exception was not thrown.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(PythonSecurityException::class, $e);
        }

        Event::assertDispatched(PythonTaskStarted::class, function (PythonTaskStarted $event): bool {
            return $event->script === 'non_existent.py';
        });

        Event::assertDispatched(PythonTaskFailed::class, function (PythonTaskFailed $event): bool {
            return $event->script === 'non_existent.py'
                && $event->exception instanceof PythonSecurityException;
        });

        Event::assertNotDispatched(PythonTaskCompleted::class);
    }
}

