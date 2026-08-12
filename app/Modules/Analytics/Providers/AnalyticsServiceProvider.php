<?php

namespace App\Modules\Analytics\Providers;

use App\Modules\Analytics\Console\ComputeAffinityCommand;
use App\Modules\Analytics\Console\PruneEventsCommand;
use App\Modules\Analytics\Console\RollupCommand;
use App\Modules\Analytics\Services\EventRecorder;
use Illuminate\Support\Facades\Schedule;
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
                RollupCommand::class,
                PruneEventsCommand::class,
                ComputeAffinityCommand::class,
            ]);

            $this->schedule();
        }
    }

    /**
     * الترتيب هو كل المسألة: التجميع قبل المحو.
     *
     * `analytics:prune` يحذف الخام بعد تسعين يوماً، و`analytics:rollup`
     * يبني منه الجداول اليومية. وتشغيلُ المحو أولاً يعني يوماً كاملاً من
     * السلوك يذهب بلا أن يُجمَّع — فرقٌ لا يظهر في أي اختبار ولا يُستدرك
     * بعد وقوعه.
     *
     * ولذلك ساعتان مختلفتان لا `->then()`: الأمران مستقلان، وفشلُ
     * التجميع لا يجوز أن يمنع المحو إلى الأبد (فينمو الجدول بلا سقف)،
     * ولا أن يقع المحو في نفس الدقيقة فيسابقه.
     *
     * والاحتفاظ تسعون يوماً وعدٌ مكتوب في README وفي صفحة الخصوصية.
     * وأمرٌ غير مجدول لا ينفّذ وعداً — وهذا أكبر ما كان ناقصاً من
     * جدولة المشروع.
     */
    private function schedule(): void
    {
        Schedule::command('analytics:rollup')->dailyAt('02:00')->withoutOverlapping();
        Schedule::command('analytics:affinity')->dailyAt('02:30')->withoutOverlapping();
        Schedule::command('analytics:prune')->dailyAt('04:00');
    }
}
