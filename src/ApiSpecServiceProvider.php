<?php

namespace Hawasly\ApiSpec;

use Hawasly\ApiSpec\Commands\GenerateApiSpec;
use Illuminate\Support\ServiceProvider;

class ApiSpecServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-spec.php', 'api-spec');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GenerateApiSpec::class]);

            $this->publishes([
                __DIR__.'/../config/api-spec.php' => config_path('api-spec.php'),
            ], 'api-spec-config');
        }
    }
}
