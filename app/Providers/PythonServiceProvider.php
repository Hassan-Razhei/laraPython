<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\Python\MakePythonScriptCommand;
use App\Console\Commands\Python\PythonHealthCommand;
use App\Console\Commands\Python\PythonInstallCommand;
use App\Contracts\Python\PythonRunnerInterface;
use App\Services\Python\PythonPathResolver;
use App\Services\Python\PythonRunner;
use App\Services\Python\PythonScriptSecurity;
use Illuminate\Support\ServiceProvider;

class PythonServiceProvider extends ServiceProvider
{
    /**
     * Register Python integration services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/python.php', 'python');

        $this->app->singleton(PythonPathResolver::class);
        $this->app->singleton(PythonScriptSecurity::class);

        $this->app->singleton(PythonRunnerInterface::class, function ($app): PythonRunner {
            return new PythonRunner(
                pathResolver: $app->make(PythonPathResolver::class),
                scriptSecurity: $app->make(PythonScriptSecurity::class),
            );
        });

        $this->app->alias(PythonRunnerInterface::class, 'python');
        $this->app->alias(PythonRunnerInterface::class, PythonRunner::class);
    }

    /**
     * Bootstrap Python integration services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PythonHealthCommand::class,
                PythonInstallCommand::class,
                MakePythonScriptCommand::class,
            ]);
        }
    }
}
