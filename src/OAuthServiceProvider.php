<?php

namespace CodeSphere\OAuth;

use CodeSphere\OAuth\Services\CodeSphereService;
use Illuminate\Support\ServiceProvider;

class OAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/codesphere.php', 'codesphere');

        $this->app->singleton(CodeSphereService::class);
        $this->app->alias(CodeSphereService::class, 'codesphere');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/codesphere.php' => config_path('codesphere.php'),
            ], 'codesphere-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'codesphere-migrations');

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }
}
