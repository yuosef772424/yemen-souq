<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $paths = glob(app_path('Modules/*/Database/Migrations'), GLOB_ONLYDIR) ?: [];

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }
}