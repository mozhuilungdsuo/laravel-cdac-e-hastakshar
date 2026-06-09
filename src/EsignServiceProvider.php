<?php

namespace Mozhuilungdsuo\LaravelCdacEHastakshar;

use Illuminate\Support\ServiceProvider;
use Mozhuilungdsuo\LaravelCdacEHastakshar\Services\EsignService;

class EsignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/esign.php', 'esign');

        $this->app->singleton(EsignService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/esign.php' => config_path('esign.php'),
        ], 'cdac-e-hastakshar-config');
    }
}
