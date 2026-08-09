<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Console\RebuildSearchBlobCommand;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Observers\ListingObserver;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Listing::observe(ListingObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildSearchBlobCommand::class,
            ]);
        }
    }
}