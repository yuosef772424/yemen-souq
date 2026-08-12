<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Console\PruneNotificationsCommand;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\Senders\FcmPushSender;
use App\Modules\Notifications\Senders\LogPushSender;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * الموفّر يوجد لثلاثة أشياء لا يغطّيها الاكتشاف — انظر docs/architecture/modules.md.
 *
 * ═══ السائق يُرمى عند اسم مجهول ═══
 *
 * لا `default => LogPushSender`. خطأ إملائي في PUSH_DRIVER يجب أن يوقف
 * الإقلاع لا أن يهبط بصمت إلى سائق يكتب في السجلّ: خادم إنتاج يبدو
 * عاملاً ولا يصل منه إشعار واحد هو أسوأ الحالتين، لأن لا شيء يشير إلى
 * السبب.
 *
 * ═══ PreferenceResolver singleton ═══
 *
 * الحلّال يخبّئ صفوف التفضيلات لكل طلب. وطلبٌ واحد قد يُشعر عدة
 * مستخدمين (إدارة تعتمد إعلانات دفعةً)، والنسخة الواحدة تعني استعلاماً
 * واحداً لكل (مستخدم، نوع) بدل استعلامين لكل قناة.
 */
class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PreferenceResolver::class);

        $this->app->bind(PushSender::class, function (): PushSender {
            $driver = (string) config('notifications.push.driver', 'log');

            return match ($driver) {
                'log' => new LogPushSender,
                'fcm' => new FcmPushSender,
                default => throw new InvalidArgumentException(
                    "سائق دفع غير معروف: {$driver}. القيم المقبولة: log, fcm."
                ),
            };
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneNotificationsCommand::class,
            ]);

            // الاحتفاظ تسعون يوماً وعدٌ معلَن — وأمرٌ غير مجدول لا ينفّذه
            Schedule::command('notifications:prune')->dailyAt('03:20');
        }
    }
}
