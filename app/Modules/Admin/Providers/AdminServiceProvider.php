<?php

namespace App\Modules\Admin\Providers;

use App\Modules\Admin\Console\BaselineReviewedCommand;
use App\Modules\Admin\Console\GrantRoleCommand;
use Illuminate\Support\ServiceProvider;

/**
 * موفّر وحدة التحكم.
 *
 * القوالب والهجرات والمسارات يكتشفها ModuleServiceProvider — لا تُسجَّل
 * هنا. هذا الموفّر قائم لسبب واحد لا يغطّيه الاكتشاف: أوامر artisan.
 *
 * ولماذا الأمر artisan أصلاً: لا سبيل إلى أول مدير من الواجهة — الشاشة
 * التي تمنح الدور محميّة بالدور نفسه. سلسلة مغلقة تُفتح من الطرفية أو
 * لا تُفتح.
 */
class AdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GrantRoleCommand::class,
                BaselineReviewedCommand::class,
            ]);
        }
    }
}
