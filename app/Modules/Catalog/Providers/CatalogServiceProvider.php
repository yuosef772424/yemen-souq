<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Console\RebuildSearchBlobCommand;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Observers\ListingObserver;
use App\Modules\Catalog\Policies\ListingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Listing::observe(ListingObserver::class);

        // الربط يدوي لأن الموديل خارج App\Models — انظر تعليق ListingPolicy
        Gate::policy(Listing::class, ListingPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildSearchBlobCommand::class,
            ]);
        }
    }
}