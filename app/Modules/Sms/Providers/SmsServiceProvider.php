<?php

namespace App\Modules\Sms\Providers;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Sms\Console\DispatchQueuedCommand;
use App\Modules\Sms\Console\PruneSmsCommand;
use App\Modules\Sms\Console\ReapSmsCommand;
use App\Modules\Sms\Console\SmsStatusCommand;
use App\Modules\Sms\Console\SmsTokenCommand;
use App\Modules\Sms\Contracts\WhatsAppTransport;
use App\Modules\Sms\Senders\NotificationSmsSender;
use App\Modules\Sms\Senders\OutboxSender;
use App\Modules\Sms\Services\ChallengeVerifier;
use App\Modules\Sms\Services\SmsOutbox;
use App\Modules\Sms\Transports\CloudApiWhatsAppTransport;
use App\Modules\Sms\Transports\HttpBridgeWhatsAppTransport;
use App\Modules\Sms\Transports\LogWhatsAppTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

/**
 * الموفّر يفعل ثلاثة أشياء لا يغطّيها الاكتشاف — وأولها هو كل الوحدة.
 *
 * ═══ ١. الاستيلاء على قناتين، بنفس السطر المتكرّر ═══
 *
 * ربط `PhoneVerificationSender` بـ`OutboxSender`، وربط `SmsSender`
 * (عقد وحدة الإشعارات) بـ`NotificationSmsSender`. كلاهما يكتب في نفس
 * الطابور بفرق الغرض وحده — `phone_verification` مقابل `notification`.
 *
 * وهو **كل** ما يصل هذه الوحدة بالنظامين. `PhoneVerificationService`
 * و`DeliverSms` بانيَيهما معاملٌ اختياري بواجهة، فالحاوية تحقن التنفيذ
 * متى كان مربوطاً وتقع إلى `null` متى لم يكن — ويعود القرار إلى `config`
 * كما كان.
 *
 * ولهذا حذف `app/Modules/Sms` يُرجع النظامين إلى ما قبلها بلا تعديل حرف
 * في وحدة الهوية أو وحدة الإشعارات. وهذا هو الشرط الذي طُلبت الوحدة لأجله.
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
        $this->app->singleton(ChallengeVerifier::class);

        $this->registerWhatsAppTransport();

        if ((bool) config('sms.enabled', false)) {
            $this->app->bind(PhoneVerificationSender::class, OutboxSender::class);
            $this->app->bind(SmsSender::class, NotificationSmsSender::class);
        }
    }

    /**
     * السائق يُرمى عند اسم مجهول — نفس قاعدة NotificationsServiceProvider.
     *
     * لا `default => LogWhatsAppTransport`: خطأ إملائي في WHATSAPP_DRIVER
     * يجب أن يوقف الإقلاع لا أن يهبط بصمت إلى سائق يكتب في السجلّ.
     * خادمُ إنتاجٍ يبدو عاملاً ولا تصل منه رسالة واحدة هو أسوأ الحالتين.
     */
    private function registerWhatsAppTransport(): void
    {
        $this->app->bind(WhatsAppTransport::class, function (): WhatsAppTransport {
            $driver = (string) config('sms.whatsapp.driver', 'log');

            return match ($driver) {
                'log' => new LogWhatsAppTransport,
                'cloud' => new CloudApiWhatsAppTransport,
                'http' => new HttpBridgeWhatsAppTransport,
                default => throw new InvalidArgumentException(
                    "سائق واتساب غير معروف: {$driver}. القيم المقبولة: log, cloud, http."
                ),
            };
        });
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
                DispatchQueuedCommand::class,
                SmsStatusCommand::class,
                SmsTokenCommand::class,
            ]);

            $this->schedule();
        }
    }

    /**
     * الجدولة داخل الوحدة لا في `routes/console.php`.
     *
     * عقد الوحدات يقول: مجلدٌ يعمل بمجرد وجوده ويُحذف بمجرد حذفه. وسطرُ
     * جدولةٍ في ملفٍ مركزي ينقض الطرفين — يحتاج تعديلاً خارج الوحدة عند
     * الإضافة، ويبقى بعد الحذف يستدعي أمراً ذهب.
     *
     * وكانت الأوامر الثلاثة تصف جدولتها في تعليقاتها ولا يجدولها شيء:
     * `sms:dispatch` غير مجدول يعني أن واتساب لا يتراجع أبداً حين تسقط
     * المهمّة الفورية، و`sms:reap` غير مجدول يعني أن سحبةً ماتت تحبس
     * رسالتها إلى الأبد — والاثنان يظهران كـ«الرمز لا يصل» بلا سبب.
     *
     * `withoutOverlapping` على ما يجري كل دقيقة: تشغيلةٌ تأخرت لا يجوز
     * أن تلتقي بالتالية على نفس الصفوف.
     */
    private function schedule(): void
    {
        Schedule::command('sms:dispatch')->everyMinute()->withoutOverlapping();
        Schedule::command('sms:reap')->everyMinute()->withoutOverlapping();
        Schedule::command('sms:prune')->dailyAt('03:10');
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

        /**
         * https شرطٌ في الإنتاج لا توصية.
         *
         * الهاتف يرسل السرّ في ترويسة كل نداء، والاستجابة تحمل رموز
         * توثيق صريحة. وعلى http يقرأ الاثنين كلُّ وسيط بين الهاتف
         * والخادم — ونداءٌ كل خمس ثوانٍ يعني أن السرّ يمرّ آلاف المرات
         * في اليوم.
         *
         * والفحص على APP_URL لا على الطلب: الأمر يقع عند الإقلاع، وقبل
         * أن يصل نداءٌ واحد. فالخطأ يظهر عند النشر لا بعد أسبوع.
         */
        if (app()->environment('production') && ! str_starts_with((string) config('app.url'), 'https://')) {
            throw new RuntimeException(
                'وحدة الرسائل مُفعَّلة على APP_URL بلا https. '
                .'سرّ العامل ورموز التوثيق تمرّ مكشوفة — اضبط شهادة قبل التشغيل.'
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
