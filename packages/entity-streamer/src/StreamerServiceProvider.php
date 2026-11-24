<?php

declare(strict_types=1);

namespace EntityStreamer;

use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Request;

class StreamerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/streamer.php',
            'streamer'
        );

        $this->app->singleton('entity-streamer', function ($app) {
            $config = $app['config']['streamer'] ?? [];

            return new StreamerManager($config);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/streamer.php' => $this->app->configPath('streamer.php'),
            ], 'streamer-config');
        }

        // Register Response Macro: response()->streamMedia($path, $disk)
        Response::macro('streamMedia', function (
            string $path,
            ?string $disk = null,
            ?Request $request = null
        ) {
            /** @var StreamerManager $streamer */
            $streamer = app('entity-streamer');

            return $streamer->stream($path)
                ->disk($disk)
                ->toResponse($request);
        });
    }
}
