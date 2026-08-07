<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * يسجّل مسارات الهجرات لكل وحدة تلقائياً.
 * أضف مجلد وحدة جديدة تحت app/Modules ولن تحتاج تعديل هذا الملف.
 */
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
