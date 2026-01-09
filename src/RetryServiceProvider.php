<?php

namespace LaravelRouteRetry;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Route;
use LaravelRouteRetry\Console\Commands\ProcessRetries;
use LaravelRouteRetry\Http\Middleware\CaptureRetryableRequest;

class RetryServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/retry.php', 'retry');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessRetries::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/retry.php' => config_path('retry.php'),
            ], 'retry-config');

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

        $this->registerRouteMacros();
    }

    protected function registerRouteMacros()
    {
        Route::macro('retry', function (?int $retries = null) {
            /** @var \Illuminate\Routing\Route $this */
            $this->action['retry'] = $retries;

            return $this->middleware(CaptureRetryableRequest::class . ($retries ? ":$retries" : ""));
        });

        Route::macro('tags', function (array $tags = []): static {
            /** @var \Illuminate\Routing\Route $this */
            $this->action['tags'] = $tags;

            return $this;
        });

        Route::macro('getTags', function () {
            /** @var \Illuminate\Routing\Route $this */
            return $this->action['tags'] ?? [];
        });
    }
}
