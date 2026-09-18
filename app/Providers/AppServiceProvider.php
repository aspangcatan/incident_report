<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        \App\Models\Incident::observe(\App\Observers\IncidentObserver::class);

        // Resource::collection() shares a single AnonymousResourceCollection
        // class across every resource type, whose $wrap static property is
        // independent of any individual resource's own $wrap override (see
        // Illuminate\Http\Resources\Json\ResourceResponse::wrapper(), which
        // resolves wrapping via get_class($collection)::$wrap rather than
        // the collected resource's class). Without this, any Resource
        // collection exposed as a top-level Inertia prop - e.g.
        // CorrectiveActionResource::collection(...) - would be wrapped in a
        // "data" key, unlike this app's own conventions and unlike a single
        // Resource instance (which disables wrapping per-class, e.g.
        // InvestigationResource::$wrap = null).
        JsonResource::withoutWrapping();
    }
}
