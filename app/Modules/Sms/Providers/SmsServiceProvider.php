<?php

namespace App\Modules\Sms\Providers;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use App\Modules\Sms\Console\PruneSmsCommand;
use App\Modules\Sms\Console\ReapSmsCommand;
use App\Modules\Sms\Console\SmsStatusCommand;
use App\Modules\Sms\Console\SmsTokenCommand;
use App\Modules\Sms\Senders\OutboxSender;
use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * الموفّر يفعل ثلاثة أشياء لا يغطّيها الاكتشاف — وأولها هو كل الوحدة.
 *
 * ═══ ١. الاستيلاء على قناة رموز التحقق ═══
 *
 * سطرٌ واحد: ربط `PhoneVerificationSender` بـ`OutboxSender`.
 *
 * وهو **كل** ما يصل هذه الوحدة بالنظام. `PhoneVerificationService` بانيها
 * `?PhoneVerificationSender $sender = null`، فالحاوية تحقن الواجهة متى
 * كانت مربوطة وتقع إلى `null` متى لم تكن — ويعود القرار إلى `config`
 * كما كان.
 *
 * ولهذا حذف `app/Modules/Sms` يُرجع النظام إلى ما قبلها بلا تعديل حرف
 * في وحدة الهوية. وهذا هو الشرط الذي طُلبت الوحدة لأجله.
 *
 * ═══ ٢. الرفض عند إعداد ناقص ═══
 *
 * وحدة مُفعَّلة بلا سرّ عاملٍ تكتب الرسائل في جدول لا يقرؤه أحد: خادمٌ
 * يبدو عاملاً، ولا يصل منه رمز واحد، ولا شيء يشير إلى السبب. نفس حجّة
 * `NotificationsServiceProvider` مع السائق المجهول — الإقلاع يتوقف بدل
 * أن يهبط بصمت.
 *
 * والرمي في `boot` لا `register`: الرسالة يجب أن تُقرأ، و`register`
 * يعمل قبل أن يجهز معالج الاستثناءات.
 *
 * ═══ ٣. حدّ معدّل للعامل ═══
 *
 * سخيّ لأن الهاتف ينادي كل بضع ثوانٍ، ومحسوب على السرّ لا على العنوان:
 * الهاتف على شبكة جوّال يتغيّر عنوانه بين نداءين.
 */
class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // singleton: الطابور بلا حالة، والنسخة الواحدة تكفي — ونداء
        // واحد قد يسحب ويؤكّد ويحصي
        $this->app->singleton(SmsOutbox::class);

        if ((bool) config('sms.enabled', false)) {
            $this->app->bind(PhoneVerificationSender::class, OutboxSender::class);
        }
    }

    public function boot(): void
    {
        if ((bool) config('sms.enabled', false)) {
            $this->guardConfiguration();
        }

        $this->registerRateLimiter();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReapSmsCommand::class,
                PruneSmsCommand::class,
                SmsStatusCommand::class,
                SmsTokenCommand::class,
            ]);
        }
    }

    /**
     * السرّ مطلوب، وطوله مطلوب.
     *
     * الطول الأدنى ليس تشدّداً: السرّ يحرس طابوراً فيه رموز توثيق، وهو
     * الشيء الوحيد بين المهاجم وبين توثيق أي حساب. و«sms123» يُخمَّن
     * قبل أن ينتبه أحد إلى السجلّ.
     */
    private function guardConfiguration(): void
    {
        $token = (string) config('sms.worker.token', '');

        if ($token === '') {
            throw new RuntimeException(
                'SMS_ENABLED مُفعَّل بلا SMS_WORKER_TOKEN. '
                .'ولّد واحداً بـ`php artisan sms:token` — وبلاه يمتلئ الطابور بلا قارئ.'
            );
        }

        if (mb_strlen($token) < 32) {
            throw new RuntimeException(
                'SMS_WORKER_TOKEN قصير جداً. الحدّ الأدنى 32 محرفاً — '
                .'السرّ يحرس طابوراً فيه رموز توثيق.'
            );
        }
    }

    private function registerRateLimiter(): void
    {
        RateLimiter::for('sms-worker', fn (Request $request): Limit => Limit::perMinute(
            (int) config('sms.worker.rate_limit', 120)
        )
            // المفتاح هو السرّ مجزَّأً لا العنوان: هاتفٌ على شبكة جوّال
            // يتغيّر عنوانه بين نداءين، فالقياس بالعنوان يعطيه ميزانية
            // جديدة كلما تنقّل — ويجمع كل من خلف المشغّل في عدّاد واحد
            ->by('sms-worker:'.sha1((string) $request->bearerToken()))
            ->response(fn (): JsonResponse => response()->json([
                'message' => 'نداءات كثيرة. أبطئ دورة العامل.',
                'code' => 'sms_worker_throttled',
            ], 429)));
    }
}
