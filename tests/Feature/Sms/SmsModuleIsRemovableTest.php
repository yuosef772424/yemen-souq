<?php

namespace Tests\Feature\Sms;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use App\Modules\Identity\Services\PhoneVerificationService;
use App\Modules\Sms\Enums\SmsStatus;
use App\Modules\Sms\Models\SmsMessage;
use App\Modules\Sms\Providers\SmsServiceProvider;
use App\Modules\Sms\Senders\OutboxSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الشرط الذي طُلبت الوحدة لأجله: قابلة للحذف كاملةً.
 *
 * ═══ ما يحرسه هذا الملف ═══
 *
 * أن الوصلة بين `Sms` و`Identity` سطرٌ واحد في الحاوية، لا تعديلٌ في
 * وحدة الهوية. فحذف `app/Modules/Sms` يُرجع النظام إلى ما قبله بلا أن
 * يُلمس حرف هناك.
 *
 * والاختبار الأول هو الحارس الفعلي: لو أضاف أحدهم يوماً `'termux'` إلى
 * الـ`match` في `PhoneVerificationService::sender()` لصار الحذف يكسر
 * الهوية — والاختبار لا يفشل. فالثاني يسدّ ذلك: يقرأ الملف ويتأكد أنه
 * لا يذكر وحدة `Sms` إطلاقاً.
 */
class SmsModuleIsRemovableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مطفأةً: الهوية تعمل بسائقها كما لو لم توجد الوحدة.
     *
     * وهذا هو ما يحدث بالضبط عند حذف المجلد — الموفّر يختفي، فلا رابط
     * في الحاوية، فتقع الحاوية إلى القيمة الافتراضية `null` في الباني.
     */
    #[Test]
    public function with_the_module_off_identity_keeps_its_own_channel(): void
    {
        // الافتراضي في config/sms.php — أي أن هذه هي حالة الإقلاع العادية
        $this->assertFalse((bool) config('sms.enabled'));
        $this->assertFalse(app()->bound(PhoneVerificationSender::class));

        $code = app(PhoneVerificationService::class)->issue('+967771234567');

        // سائق العرض هو الافتراضي — يُرجع الرمز ولا يكتب في الطابور
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $this->assertDatabaseCount('sms_messages', 0);
    }

    /** مُفعَّلةً: تستولي على القناة بسطر واحد */
    #[Test]
    public function with_the_module_on_the_code_goes_to_the_outbox(): void
    {
        $this->enableModule();

        $this->assertInstanceOf(OutboxSender::class, app(PhoneVerificationSender::class));

        $code = app(PhoneVerificationService::class)->issue('+967771234567');

        // القناة لا تعرض الرمز — يوصّله الهاتف
        $this->assertNull($code);

        $message = SmsMessage::sole();

        $this->assertSame('+967771234567', $message->to);
        $this->assertSame('phone_verification', $message->purpose);
        $this->assertSame(SmsStatus::Queued, $message->status);
    }

    /**
     * أجل الرسالة = عمر الرمز، لا إعداد ثانٍ.
     *
     * إعدادان لأجلٍ واحد يتفرّقان عند أول تعديل — فيخرج من الطابور رمزٌ
     * انتهت صلاحيته قبل أن يصل، ويقرأ صاحبه الرفضَ عطلاً في المنصة.
     */
    #[Test]
    public function the_message_dies_with_the_code_it_carries(): void
    {
        $this->enableModule();

        config(['identity.phone_verification.ttl_minutes' => 3]);

        app(PhoneVerificationService::class)->issue('+967771234567');

        $this->assertEqualsWithDelta(
            now()->addMinutes(3)->timestamp,
            SmsMessage::sole()->expires_at->timestamp,
            5,
        );
    }

    /**
     * الحارس ضد الارتداد: وحدة الهوية لا تذكر وحدة الرسائل.
     *
     * أول `'termux' => app(OutboxSender::class)` يُضاف إلى الـ`match`
     * يجعل حذف المجلد يكسر التسجيل والدخول — ولا شيء غير هذا الاختبار
     * يمنعه.
     */
    #[Test]
    public function identity_never_names_the_sms_module(): void
    {
        $root = __DIR__.'/../../../app/Modules/Identity';

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), 'App\\Modules\\Sms')) {
                $offenders[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['وحدة الهوية تذكر وحدة الرسائل — فحذف الثانية يكسر الأولى.'],
            $offenders,
            ['', 'الوصلة الوحيدة المسموحة: ربط PhoneVerificationSender في SmsServiceProvider.']
        )));
    }

    /** والوحدة مُفعَّلة بلا سرّ ترفض الإقلاع بدل أن تملأ طابوراً بلا قارئ */
    #[Test]
    public function enabling_without_a_secret_stops_the_boot(): void
    {
        $this->expectExceptionMessage('SMS_WORKER_TOKEN');

        $this->bootModuleWith(['sms.enabled' => true, 'sms.worker.token' => null]);
    }

    #[Test]
    public function a_short_secret_is_refused(): void
    {
        $this->expectExceptionMessage('قصير جداً');

        $this->bootModuleWith(['sms.enabled' => true, 'sms.worker.token' => 'short']);
    }

    // ─── مساعدات ───

    private function enableModule(): void
    {
        $this->bootModuleWith([
            'sms.enabled' => true,
            'sms.worker.token' => str_repeat('t', 64),
        ]);
    }

    /**
     * إعادة تشغيل موفّر الوحدة بإعداد مختلف.
     *
     * ولماذا لا `refreshApplication()`: تطبيقٌ جديد يعني اتصال SQLite
     * جديداً على `:memory:` — أي قاعدةً فارغةً بلا الجداول التي هاجرها
     * `RefreshDatabase`. وإعادة الموفّر وحده تكفي: هو الموضع الوحيد
     * الذي يقرأ الإعداد.
     *
     * @param  array<string, mixed>  $config
     */
    private function bootModuleWith(array $config): void
    {
        config($config);

        $provider = new SmsServiceProvider($this->app);
        $provider->register();
        $provider->boot();
    }
}
