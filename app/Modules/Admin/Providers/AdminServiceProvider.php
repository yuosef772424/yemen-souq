<?php

namespace App\Modules\Admin\Providers;

use App\Modules\Admin\Console\BaselineReviewedCommand;
use App\Modules\Admin\Console\GrantRoleCommand;
use Illuminate\Support\ServiceProvider;

/**
 * موفّر وحدة التحكم.
 *
 * يسجّل القوالب والهجرات بنفسه وإن كان ModuleServiceProvider يمسح
 * app/Modules تلقائياً: التسجيل المكرَّر لنفس المسار لا يضرّ (لارافل
 * يدمج التلميحات)، وغيابه يعني وحدة تعتمد على سلوك ملف خارجها. الوحدة
 * تعمل بمجرد نسخها.
 *
 * ولماذا الأمر artisan هنا: لا سبيل إلى أول مدير من الواجهة — الشاشة
 * التي تمنح الدور محميّة بالدور نفسه. سلسلة مغلقة تُفتح من الطرفية أو
 * لا تُفتح.
 */
class AdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/Views', 'admin');
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GrantRoleCommand::class,
                BaselineReviewedCommand::class,
            ]);
        }
    }
}
