<?php

use App\Modules\Analytics\Providers\AnalyticsServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Engagement\Providers\EngagementServiceProvider;
use App\Modules\Search\Providers\SearchServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    CatalogServiceProvider::class,
    AnalyticsServiceProvider::class,
    SearchServiceProvider::class,
    EngagementServiceProvider::class,
    App\Modules\Admin\Providers\AdminServiceProvider::class,
    App\Modules\Merchant\Providers\MerchantServiceProvider::class,
];