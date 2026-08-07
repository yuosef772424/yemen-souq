<?php

use App\Providers\AppServiceProvider;

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\ModuleServiceProvider::class,
    App\Modules\Catalog\Providers\CatalogServiceProvider::class,
];


