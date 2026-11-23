<?php

declare(strict_types=1);

namespace App\Facades;

use App\Contracts\Python\PythonRunnerInterface;
use App\Services\Python\PythonResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PythonResult run(string $script, array $payload = [], array $args = [], ?int $timeout = null)
 * @method static PythonResult inline(string $code, array $args = [], ?int $timeout = null)
 * @method static PythonResult raw(array $arguments, ?int $timeout = null, ?string $cwd = null, array $env = [])
 * @method static \Illuminate\Foundation\Bus\PendingDispatch queue(string $script, array $payload = [], array $args = [], ?int $timeout = null)
 * @method static \App\Services\Python\Services\PythonServiceClient service(string $name = 'ai')
 * @method static \App\Services\Python\Services\PythonServiceManager serviceManager()
 *
 * @see \App\Services\Python\PythonRunner
 */
class Python extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PythonRunnerInterface::class;
    }
}

