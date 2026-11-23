<?php

declare(strict_types=1);

namespace LaraPython;

use Illuminate\Support\ServiceProvider;
use LaraPython\Console\Commands\MakePythonScriptCommand;
use LaraPython\Console\Commands\PythonHealthCommand;
use LaraPython\Console\Commands\PythonInstallCommand;
use LaraPython\Console\Commands\PythonServeCommand;
use LaraPython\Contracts\PythonRunnerInterface;
use LaraPython\Services\PythonPathResolver;
use LaraPython\Services\PythonRunner;
use LaraPython\Services\PythonScriptSecurity;
use LaraPython\Services\Services\PythonServiceManager;

class LaraPythonServiceProvider extends ServiceProvider
{
    /**
     * Register services into container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/python.php', 'python');

        $this->app->singleton(PythonPathResolver::class);
        $this->app->singleton(PythonScriptSecurity::class);
        $this->app->singleton(PythonServiceManager::class);

        $this->app->singleton(PythonRunnerInterface::class, function ($app): PythonRunner {
            return new PythonRunner(
                pathResolver: $app->make(PythonPathResolver::class),
                scriptSecurity: $app->make(PythonScriptSecurity::class),
                serviceManager: $app->make(PythonServiceManager::class),
            );
        });

        $this->app->alias(PythonRunnerInterface::class, 'python');
        $this->app->alias(PythonRunnerInterface::class, PythonRunner::class);
    }

    /**
     * Bootstrap services and publish assets.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Register commands
            $this->commands([
                PythonHealthCommand::class,
                PythonInstallCommand::class,
                MakePythonScriptCommand::class,
                PythonServeCommand::class,
            ]);

            // Configuration publish
            $this->publishes([
                __DIR__.'/../config/python.php' => config_path('python.php'),
            ], 'larapython-config');

            // Python libraries and scripts publish
            $this->publishes([
                __DIR__.'/../resources/python/lib/larabridge.py' => base_path('python/lib/larabridge.py'),
                __DIR__.'/../resources/python/scripts/analyze.py' => base_path('python/scripts/analyze.py'),
            ], 'larapython-scripts');

            // Boost AI Guidelines publish
            $this->publishes([
                __DIR__.'/../resources/rules/python-runtime.md' => base_path('.ai/rules/python-runtime.md'),
                __DIR__.'/../resources/rules/python-security.md' => base_path('.ai/rules/python-security.md'),
            ], 'larapython-boost');

            // Publish all assets
            $this->publishes([
                __DIR__.'/../config/python.php' => config_path('python.php'),
                __DIR__.'/../resources/python/lib/larabridge.py' => base_path('python/lib/larabridge.py'),
                __DIR__.'/../resources/python/scripts/analyze.py' => base_path('python/scripts/analyze.py'),
                __DIR__.'/../resources/rules/python-runtime.md' => base_path('.ai/rules/python-runtime.md'),
                __DIR__.'/../resources/rules/python-security.md' => base_path('.ai/rules/python-security.md'),
            ], 'larapython');
        }
    }
}

