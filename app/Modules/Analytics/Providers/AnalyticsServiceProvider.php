<?php

namespace App\Modules\Analytics\Providers;

use App\Modules\Analytics\Services\EventRecorder;
use Illuminate\Support\ServiceProvider;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // singleton ضروري: كل الأحداث في الطلب يجب أن تتجمّع
        // في نفس المخزن المؤقت لتُكتب دفعة واحدة
        $this->app->singleton(EventRecorder::class);
    }

    public function boot(): void
    {
        // الكتابة تحدث بعد إرسال الاستجابة للمتصفح — لا تأخير على المستخدم
        $this->app->terminating(function (): void {
            if ($this->app->resolved(EventRecorder::class)) {
                $this->app->make(EventRecorder::class)->flush();
            }
        });
        if ($this->app->runningInConsole()) {
    $this->commands([
        \App\Modules\Analytics\Console\RollupCommand::class,
        \App\Modules\Analytics\Console\PruneEventsCommand::class,
    ]);
}
    }
}
