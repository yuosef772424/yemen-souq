<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * يسجّل الهجرات والقوالب لكل وحدة تلقائياً.
 * أضف مجلد وحدة جديدة تحت app/Modules ولن تحتاج تعديل هذا الملف.
 *
 * القوالب تُستدعى بمساحة اسم الوحدة بحروف صغيرة:
 *   app/Modules/Search/Resources/Views/index.blade.php  ->  view('search::index')
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerViews();
    }

    private function registerMigrations(): void
    {
        $paths = glob(app_path('Modules/*/Database/Migrations'), GLOB_ONLYDIR) ?: [];

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }

    private function registerViews(): void
    {
        foreach (glob(app_path('Modules/*/Resources/Views'), GLOB_ONLYDIR) ?: [] as $path) {
            $module = basename(dirname($path, 2));

            $this->loadViewsFrom($path, Str::lower($module));
        }
    }
}
