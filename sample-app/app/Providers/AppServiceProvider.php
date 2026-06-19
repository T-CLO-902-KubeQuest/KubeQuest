<?php

namespace App\Providers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Make the native /up health endpoint reflect real application state.
        // It is used by the Kubernetes readiness probe, so a failure here keeps
        // the pod out of rotation (and a canary that never becomes Ready is
        // rolled back by Argo Rollouts).
        Event::listen(function (DiagnosingHealth $event) {
            // Verify the database is actually reachable.
            DB::connection()->getPdo();

            // Demo hook: force readiness to fail to showcase the automatic
            // canary rollback without shipping a deliberately broken image.
            if (env('APP_FORCE_UNHEALTHY', false)) {
                throw new \RuntimeException('Forced unhealthy for canary demo');
            }
        });
    }
}
