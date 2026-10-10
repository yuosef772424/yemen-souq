<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * حارس نصوص واجهة العميل: لا شروحَ تقنية داخلية.
 *
 * الطابور، وكيف يُسحب، وحالة إعداد المزوّد، وأسماء الجداول — مكانها
 * التوثيق أو سجلّ الخادم، لا شاشة المشتري. الرسالة للمستخدم عامة والتفصيل
 * في السجلّ (OWASP Error Handling Cheat Sheet).
 *
 * ═══ ما يُفحص وما يُستثنى ═══
 *
 * - resources/views/** و app/Modules/*​/Resources/Views/**، بلا وحدة Admin
 *   (واجهة الإدارة يقرؤها موظفون)، وملفات lang/ إن وُجدت.
 * - قبل الفحص يُحذف ما لا يراه العميل: تعليقات Blade والـHTML، وكتل
 *   `@env(...)` (للتطوير)، و`@php`، والتعبيرات `{{ }}` و`{!! !!}` (شيفرة
 *   لا نصّ، وفيها أسماء مسارات وأصناف تُنتج نتائج إيجابية خاطئة).
 * - التطبيع قبل المطابقة: حذف التشكيل والتطويل حتى لا يفلت «مُفعَّل»
 *   لأن أحدهم كتبها «مفعل».
 *
 * القائمة ضيّقة بقصد: مصطلحات لا تَرِد في نصٍّ سليم (لا «سحب» وحدها ولا
 * «sms»). وتجربتها على نصوص سليمة ومخالفة في الاختبارين الأخيرين.
 */
class CustomerFacingCopyTest extends TestCase
{
    /**
     * مصطلحات داخلية. النمط يُطبَّق على نصّ مُطبَّع (بلا تشكيل)، بحالة
     * أحرف غير حسّاسة، وبحدود كلمة في اللاتينية لتجنّب «queued» داخل كلمة أخرى.
     */
    private const FORBIDDEN = [
        'طابور' => '/طابور/u',
        'يسحب / يسحبها' => '/(?<!\p{Arabic})[وف]?يسحب/u',
        'outbox' => '/\boutbox\b/iu',
        'queue' => '/\bqueue[ds]?\b/iu',
        'failover' => '/\bfail-?over\b/iu',
        'polling' => '/\bpolling\b/iu',
        'driver' => '/\bdrivers?\b/iu',
        'Termux' => '/\btermux\b/iu',
        'تيرمكس' => '/تيرمكس/u',
        'sms_messages' => '/\bsms_messages\b/iu',
        'app_notifications' => '/\bapp_notifications\b/iu',
        'مزوّد الرسائل' => '/مزود الرسائل/u',
        'غير مُفعَّل حالياً' => '/غير مفعل حاليا/u',
        'webhook' => '/\bwebhooks?\b/iu',
        'cron' => '/\bcron\b/iu',
        'stack trace' => '/\bstack ?trace\b/iu',
        'artisan' => '/\bartisan\b/iu',
        'متغيرات البيئة' => '/SMS_[A-Z_]{3,}|\.env\b/u',
    ];

    /**
     * استثناءات صريحة وموقّتة.
     *
     * verify-phone وreset-password فيهما اليوم «غير مُفعَّل حالياً» و«مزوّد
     * الرسائل». مهمة الدخول (Identity) ستعيد كتابتهما في موجة لاحقة وتحذف
     * هذين السطرين من هنا. حتى ذلك الحين يبقى الاختبار ناجحاً.
     *
     * وقد كشف الحارس مخالفة ثالثة خارج نطاق هذه المهمة: stats.blade.php
     * (إحصاءات حساب التاجر) يطلب من العميل تشغيل `php artisan
     * analytics:rollup`. مكانها الصحيح سجلّ الخادم أو التوثيق، وعبارة
     * عامة مثل «التفصيل اليومي قيد الإعداد». استُثني الملف هنا مؤقتاً
     * حتى يصلحه صاحب وحدة Catalog، وعندها يُحذف السطر (يفرض ذلك الاختبار
     * التالي).
     *
     * ولا يُضاف إلى هذه القائمة شيء آخر: نصٌّ جديد مخالف يُصلَح لا يُستثنى.
     */
    private const TEMPORARY_EXCEPTIONS = [
        'app/Modules/Identity/Resources/Views/verify-phone.blade.php',
        'app/Modules/Identity/Resources/Views/reset-password.blade.php',
        'app/Modules/Catalog/Resources/Views/account/stats.blade.php',
    ];

    #[Test]
    public function customer_facing_views_carry_no_internal_technical_terms(): void
    {
        $violations = [];

        foreach ($this->customerFacingFiles() as $relative => $absolute) {
            if (in_array($relative, self::TEMPORARY_EXCEPTIONS, true)) {
                continue;
            }

            foreach ($this->violationsIn(File::get($absolute)) as $term) {
                $violations[] = "{$relative}: {$term}";
            }
        }

        $this->assertSame(
            [],
            $violations,
            "نصوص تقنية داخلية في واجهة العميل — انقلها إلى التوثيق أو السجلّ:\n".implode("\n", $violations)
        );
    }

    /** استثناءٌ لم يعد لازماً يُحذف: مهمة الدخول حين تنتهي تُسقط السطر هنا */
    #[Test]
    public function every_temporary_exception_still_has_a_violation_to_excuse(): void
    {
        foreach (self::TEMPORARY_EXCEPTIONS as $relative) {
            $path = base_path($relative);

            $this->assertFileExists($path, "الاستثناء يشير إلى ملف غير موجود: {$relative}");
            $this->assertNotSame(
                [],
                $this->violationsIn(File::get($path)),
                "{$relative} صار نظيفاً — احذفه من TEMPORARY_EXCEPTIONS."
            );
        }
    }

    // ─── تجربة المطابِق نفسه ───

    /** نصوص سليمة يجب ألا يلتقطها المطابِق */
    public static function cleanCopy(): array
    {
        return [
            'preferences note' => ['قد تصل الرسالة النصية متأخرة بضع ثوانٍ.'],
            'service unavailable' => ['هذه الخدمة غير متاحة حاليًا، حاول لاحقًا.'],
            'challenge intro' => ['أرسل أنت هذا الرمز إلينا من جوالك — ويتوثّق رقمك بمجرد وصول الرسالة.'],
            'tariff' => ['تُحسب عليك رسالة واحدة بتعرفة شركة الاتصالات.'],
            'verification code' => ['رمز التحقق: 123456'],
            'plain drive word' => ['السائق ينقل البضاعة وقود السيارة'],
            'withdrawal word' => ['يمكنك سحب إعلانك في أي وقت'],
            'sms alone' => ['وصلتك رسالة SMS على جوالك'],
            'queued inside a word' => ['requeuedness is not a word we ship'],
            'enabled phrasing' => ['هذه الميزة مفعّلة لحسابك'],
            'class name in code' => ['{{ \App\Modules\Identity\Support\YemeniPhone::forDisplay($phone) }}'],
            'blade comment' => ['{{-- الطابور يُسحب منه عبر polling --}} نص سليم'],
            'html comment' => ['<!-- outbox queue driver --> نص سليم'],
            'env block' => ["@env('local') يسحبها الطابور عبر Termux @endenv نص سليم"],
            'php block' => ["@php \$queue = 'outbox'; @endphp نص سليم"],
        ];
    }

    #[Test]
    #[DataProvider('cleanCopy')]
    public function the_matcher_leaves_clean_copy_alone(string $text): void
    {
        $this->assertSame([], $this->violationsIn($text));
    }

    /** نصوص مخالفة يجب أن يلتقطها المطابِق — كلٌّ بالمصطلح الذي يخصّها */
    public static function leakyCopy(): array
    {
        return [
            'the original preferences line' => ['تنتظر هاتفاً يسحبها من الطابور.', ['طابور', 'يسحب / يسحبها']],
            'with vowels and prefix' => ['فَيُسحَبُ ثم يَسْحَبُها الهاتف', ['يسحب / يسحبها']],
            'provider state' => ['حين يُفعَّل مزوّد الرسائل، يصل الرمز', ['مزوّد الرسائل']],
            'disabled state, no diacritics' => ['توصيل الرسائل النصية غير مفعل حاليا', ['غير مُفعَّل حالياً']],
            'disabled state, with diacritics' => ['غير مُفعَّل حالياً', ['غير مُفعَّل حالياً']],
            'english internals' => ['Outbox queue failover polling driver', ['outbox', 'queue', 'failover', 'polling', 'driver']],
            'termux' => ['عامل Termux يرسل', ['Termux']],
            'termux arabic' => ['عامل تيرمكس يرسل', ['تيرمكس']],
            'table name' => ['سجل في sms_messages', ['sms_messages']],
            'env var' => ['فعّل SMS_ENABLED في الإعدادات', ['متغيرات البيئة']],
            'in a blade echo-free line next to clean text' => ["نص سليم\nالرسالة في الطابور", ['طابور']],
        ];
    }

    #[Test]
    #[DataProvider('leakyCopy')]
    public function the_matcher_catches_internal_terms(string $text, array $expected): void
    {
        $found = $this->violationsIn($text);

        foreach ($expected as $term) {
            $this->assertContains($term, $found, "لم يُلتقط: {$term}");
        }
    }

    // ─── الأدوات ───

    /** @return array<string, string> المسار النسبي => المسار المطلق */
    private function customerFacingFiles(): array
    {
        $files = [];

        $roots = array_merge(
            [base_path('resources/views')],
            glob(base_path('app/Modules/*/Resources/Views'), GLOB_ONLYDIR) ?: [],
            array_filter([base_path('lang'), base_path('resources/lang')], 'is_dir'),
        );

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                if (str_starts_with($relative, 'app/Modules/Admin/')) {
                    continue;
                }

                if (! str_ends_with($relative, '.blade.php') && ! str_ends_with($relative, '.php')) {
                    continue;
                }

                $files[$relative] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'لم يُعثر على أي ملف عرض — تغيّر الهيكل؟');

        return $files;
    }

    /** @return list<string> أسماء المصطلحات الملتقَطة */
    private function violationsIn(string $source): array
    {
        $text = $this->visibleText($source);

        $found = [];

        foreach (self::FORBIDDEN as $name => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /** ما يبقى بعد حذف ما لا يراه العميل، مع تطبيع التشكيل */
    private function visibleText(string $source): string
    {
        $patterns = [
            '/\{\{--.*?--\}\}/su',                 // تعليق Blade
            '/<!--.*?-->/su',                      // تعليق HTML
            '/@env\s*\(.*?\).*?@endenv/su',        // كتل التطوير
            '/@php\b.*?@endphp/su',                // شيفرة
            '/\{!!.*?!!\}/su',                     // تعبير غير مُهرَّب
            '/\{\{.*?\}\}/su',                     // تعبير
        ];

        $text = (string) preg_replace($patterns, ' ', $source);

        // التشكيل والتطويل: «مُفعَّل» و«مفعل» عندنا واحد. والهمزات تبقى كما هي.
        return (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text);
    }
}
