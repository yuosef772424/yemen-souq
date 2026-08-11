<?php

/**
 * scaffold-health.php — إصلاح ما كشفته لوحة القيادة.
 *
 * التشغيل من جذر المشروع:
 *     php scaffold-health.php
 *
 *   1. «أوامر تصحيح منسية» كان إنذاراً كاذباً: in_array( تحتوي «ray(»
 *      و->add( تحتوي «dd(». استُبدلت المطابقة النصية بنمط بحدود كلمة،
 *      وصار للنمط اختبار وحدة يحرسه.
 *   2. أمر admin:baseline-reviewed لتفريغ طابور المراجعة من الـ160
 *      إعلاناً التي وُجدت قبل وجود اللوحة — بصفّ واحد في سجل المساءلة.
 */

declare(strict_types=1);

$root = __DIR__;

$files = [

'app/Modules/Shared/Support/HealthCheck.php' => <<<'FILE'
<?php

namespace App\Modules\Shared\Support;

use App\Modules\Analytics\Events\EventName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فحص صحة المشروع.
 *
 * لماذا صنف لا سكربت مؤقت: كل فحص كُتب في ملف يُحذف بعد الاستخدام يُعاد
 * اختراعه من الذاكرة في المرة القادمة — ناقصاً. الفحوص هنا تُلتزم في Git
 * وتُشغَّل على الخادم أيضاً.
 *
 * كل فحص يُرجع حالة من ثلاث:
 *   ok   — سليم
 *   warn — يحتاج انتباهاً ولا يعطّل شيئاً
 *   fail — عطل فعلي، وأغلب هذه الأعطال صامتة لا ترمي استثناءً
 *
 * التركيز على الأعطال الصامتة بقصد. الاستثناء يخبرك بنفسه؛ أما إعلان
 * بلا price_in_base فيختفي من كل فلترة بالسعر بلا خطأ ولا تحذير.
 */
final class HealthCheck
{
    /** @var array<int, array{name: string, status: string, detail: string}> */
    private array $results = [];

    /** @return array<int, array{name: string, status: string, detail: string}> */
    public function run(): array
    {
        $this->results = [];

        $this->checkEnvironment();
        $this->checkSchema();
        $this->checkCurrencies();
        $this->checkListingData();
        $this->checkAnalytics();
        $this->checkStorage();
        $this->checkDebugLeftovers();

        return $this->results;
    }

    public function hasFailures(): bool
    {
        foreach ($this->results as $result) {
            if ($result['status'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    // ---- البيئة ----

    private function checkEnvironment(): void
    {
        $this->add('إصدار PHP', version_compare(PHP_VERSION, '8.2', '>=') ? 'ok' : 'fail', PHP_VERSION);

        $this->add('محرك قاعدة البيانات', 'ok', DB::getDriverName());

        $queue = config('queue.default');

        // sync يكتب الأحداث فوراً بلا عامل — أضمن للعرض، وأبطأ قليلاً.
        // database بلا queue:work يعني أن الأحداث لا تُكتب إطلاقاً.
        if ($queue === 'sync') {
            $this->add('سائق الطابور', 'ok', 'sync — الأحداث تُكتب فوراً بلا عامل');
        } else {
            $this->add('سائق الطابور', 'warn', "{$queue} — يحتاج queue:work يعمل، وإلا لا تُسجَّل الأحداث");
        }

        $this->add(
            'APP_KEY',
            config('app.key') ? 'ok' : 'fail',
            config('app.key') ? 'موجود' : 'فارغ — أي تشفير أو جلسة سيفشل'
        );
    }

    // ---- المخطط ----

    private function checkSchema(): void
    {
        $required = [
            'listings' => ['search_blob', 'price_in_base', 'rate_used', 'rate_date', 'title_normalized'],
            'exchange_rates' => ['effective_date', 'rate_to_base'],
            'user_events' => ['event_name', 'occurred_at', 'anon_id'],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->add("جدول {$table}", 'fail', 'غير موجود — هجرة لم تُشغَّل');

                continue;
            }

            $missing = array_values(array_filter(
                $columns,
                fn (string $column): bool => ! Schema::hasColumn($table, $column)
            ));

            $this->add(
                "أعمدة {$table}",
                $missing === [] ? 'ok' : 'fail',
                $missing === [] ? 'كاملة' : 'ناقص: ' . implode('، ', $missing) . ' — شغّل php artisan migrate'
            );
        }

        $pending = $this->pendingMigrations();

        $this->add(
            'الهجرات',
            $pending === [] ? 'ok' : 'fail',
            $pending === [] ? 'كلها منفَّذة' : count($pending) . ' معلّقة: ' . implode('، ', array_slice($pending, 0, 3))
        );
    }

    /** @return array<int, string> */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return ['جدول migrations غير موجود'];
        }

        $ran = $migrator->getRepository()->getRan();

        $paths = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(app_path('Modules/*/Database/Migrations/*.php')) ?: [],
        );

        $pending = [];

        foreach ($paths as $path) {
            $name = basename($path, '.php');

            if (! in_array($name, $ran, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    // ---- العملات وأسعار الصرف ----

    private function checkCurrencies(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        $baseCount = DB::table('currencies')->where('is_base', true)->count();

        $this->add(
            'عملة الأساس',
            $baseCount === 1 ? 'ok' : 'fail',
            match (true) {
                $baseCount === 0 => 'لا توجد — Currency::base() سيرمي استثناءً',
                $baseCount === 1 => 'واحدة كما يجب',
                default => "{$baseCount} عملات أساس — التحويل غير محدَّد",
            }
        );

        $active = DB::table('currencies')->where('is_active', true)->pluck('code');
        $today = now()->toDateString();
        $withoutRate = [];

        foreach ($active as $code) {
            $exists = DB::table('exchange_rates')
                ->where('currency_code', $code)
                ->where('effective_date', '<=', $today)
                ->exists();

            if (! $exists) {
                $withoutRate[] = $code;
            }
        }

        $this->add(
            'أسعار الصرف السارية',
            $withoutRate === [] ? 'ok' : 'fail',
            $withoutRate === []
                ? count($active) . ' عملة نشطة، لكلٍّ سعر'
                : 'بلا سعر: ' . implode('، ', $withoutRate) . ' — أي إعلان بها يُسقط الصفحة'
        );
    }

    // ---- بيانات الإعلانات: الأعطال الصامتة ----

    private function checkListingData(): void
    {
        if (! Schema::hasTable('listings')) {
            return;
        }

        $active = DB::table('listings')->where('status', 'active')->whereNull('deleted_at');

        if (Schema::hasColumn('listings', 'search_blob')) {
            $blank = (clone $active)->whereNull('search_blob')->count();

            $this->add(
                'search_blob',
                $blank === 0 ? 'ok' : 'fail',
                $blank === 0
                    ? 'مبني لكل الإعلانات النشطة'
                    : "{$blank} إعلاناً بعمود فارغ — لا يظهر في أي بحث نصي. شغّل php artisan catalog:rebuild-search-blob"
            );
        }

        $missingBase = (clone $active)
            ->whereNotNull('price_amount')
            ->whereNull('price_in_base')
            ->count();

        $this->add(
            'price_in_base',
            $missingBase === 0 ? 'ok' : 'fail',
            $missingBase === 0
                ? 'محسوب لكل إعلان مسعَّر'
                : "{$missingBase} إعلاناً بسعر بلا قيمة موحّدة — يختفي من الفلترة بالسعر بصمت"
        );

        $expired = (clone $active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        $this->add(
            'إعلانات منتهية',
            $expired === 0 ? 'ok' : 'warn',
            $expired === 0
                ? 'لا يوجد'
                : "{$expired} إعلاناً حالته active وتاريخه منتهٍ — مستبعد من النتائج، ويستحق تنظيفاً"
        );

        $genericSlug = (clone $active)->where('slug', 'like', 'listing-%')->count();

        $this->add(
            'الروابط',
            $genericSlug === 0 ? 'ok' : 'warn',
            $genericSlug === 0
                ? 'وصفية'
                : "{$genericSlug} رابطاً بالصيغة العامة listing-xxxx — بيانات سابقة لإصلاح الـslug العربي"
        );
    }

    // ---- التحليلات ----

    private function checkAnalytics(): void
    {
        foreach (['jobs', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();
            $isFailed = $table === 'failed_jobs';

            $this->add(
                $isFailed ? 'مهام فاشلة' : 'مهام معلّقة',
                $count === 0 ? 'ok' : ($isFailed ? 'fail' : 'warn'),
                $count === 0
                    ? 'لا يوجد'
                    : ($isFailed
                        ? "{$count} — أحداث لم تُكتب أبداً. php artisan queue:failed"
                        : "{$count} — الطابور بلا عامل يعمل")
            );
        }

        if (! Schema::hasTable('user_events')) {
            return;
        }

        $today = now()->toDateString();
        $eventsToday = DB::table('user_events')->whereDate('occurred_at', $today)->count();

        $this->add('أحداث اليوم', $eventsToday > 0 ? 'ok' : 'warn', (string) $eventsToday);

        // مصيدة التاريخ من دليل التشخيص: التجميع ينجح ويُجمّع صفراً
        if ($eventsToday > 0 && Schema::hasTable('listing_daily_stats')) {
            $rolled = DB::table('listing_daily_stats')->where('stat_date', $today)->exists();

            $this->add(
                'التجميع اليومي',
                $rolled ? 'ok' : 'warn',
                $rolled
                    ? "مبني لتاريخ {$today}"
                    : "أحداث اليوم موجودة ولا صفوف مجمّعة. php artisan analytics:rollup --date={$today}"
            );
        }

        $recorded = DB::table('user_events')->distinct()->pluck('event_name')->all();
        $declared = array_map(fn (EventName $case): string => $case->value, EventName::cases());

        $unknown = array_values(array_diff($recorded, $declared));

        $this->add(
            'أسماء أحداث غير معروفة',
            $unknown === [] ? 'ok' : 'fail',
            $unknown === [] ? 'لا يوجد' : implode('، ', $unknown) . ' — مسجَّلة ولا يعرفها الـenum فلا تُجمَّع'
        );

        // العكس: حالة معرَّفة ولا يستدعيها كود. الميزة تبدو معطوبة لا مؤجَّلة.
        $neverRecorded = array_values(array_diff($declared, $recorded));

        $this->add(
            'أحداث معرَّفة بلا استخدام',
            $neverRecorded === [] ? 'ok' : 'warn',
            $neverRecorded === []
                ? 'كل الحالات مستخدَمة'
                : implode('، ', $neverRecorded) . ' — أي تحليل يعتمد عليها يُخرج صفراً دائماً'
        );
    }

    // ---- الملفات ----

    private function checkStorage(): void
    {
        $link = public_path('storage');

        $this->add(
            'رابط التخزين',
            file_exists($link) ? 'ok' : 'fail',
            file_exists($link) ? 'موجود' : 'مفقود — الصور لا تُعرَض. php artisan storage:link'
        );

        if (! Schema::hasTable('listing_images')) {
            return;
        }

        $missing = 0;

        DB::table('listing_images')->select('path')->orderBy('id')->chunk(200, function ($rows) use (&$missing): void {
            foreach ($rows as $row) {
                if (! file_exists(storage_path('app/public/' . ltrim((string) $row->path, '/')))) {
                    $missing++;
                }
            }
        });

        $this->add(
            'ملفات الصور',
            $missing === 0 ? 'ok' : 'warn',
            $missing === 0 ? 'كل صف له ملف' : "{$missing} صفاً بلا ملف على القرص"
        );
    }

    /**
     * أوامر تصحيح منسية.
     * دليل التشخيص يذكرها كفحص قبل النشر — فلتكن آلية لا يدوية.
     *
     * ═══ تعبير نمطي بحدود كلمة، لا مطابقة نصّ فرعي ═══
     *
     * القاعدة القديمة كانت str_contains على 'ray(' و'd'.'d(' — فكانت
     * تُبلّغ عن كل ملف فيه in_array( (لأنها تحتوي «ray(») وكل ملف فيه
     * ->add( (لأنها تحتوي «dd(»). النتيجة: الفحص يشير إلى خمسة ملفات
     * نظيفة في كل مرة.
     *
     * وهذا أسوأ من غياب الفحص. فحص يصرخ بلا سبب يُدرَّب عليه القارئ حتى
     * يتجاهله — فحين يُترك dd( حقيقياً في متحكّم يمرّ بين الأسماء الخمسة
     * المعتادة. الإنذار الكاذب لا يُضعف الفحص بل يُبطله.
     *
     * الحدّ الأيسر (?<![\w$]) هو كل الفرق: الحرف قبل الاسم لا يجوز أن
     * يكون حرفاً — فـin_array( يُقصى وdd( يُلتقط. و«>» مسموح بقصد:
     * ->dump( على استعلام أمرُ تصحيح حقيقي.
     *
     * وvar_dump قبل dump في البدائل ضرورة لا ترتيب: لو جاءت dump أولاً
     * لطابقت داخل var_dump( فيُقصيها الشرطة السفلية قبلها، فيمرّ
     * var_dump( بلا كشف.
     */
    public const DEBUG_PATTERN = '/(?<![\w$])(?:var_dump|dump|dd|ray)\s*\(/';

    private function checkDebugLeftovers(): void
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'HealthCheck.php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match(self::DEBUG_PATTERN, $contents) === 1) {
                $found[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        sort($found);

        $this->add(
            'أوامر تصحيح منسية',
            $found === [] ? 'ok' : 'warn',
            $found === []
                ? 'نظيف'
                : count($found) . ' ملفاً: ' . implode('، ', array_slice($found, 0, 5))
        );
    }

    private function add(string $name, string $status, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    }
}
FILE,

'app/Modules/Admin/Enums/AdminAction.php' => <<<'FILE'
<?php

namespace App\Modules\Admin\Enums;

/**
 * أنواع الإجراءات الإدارية.
 *
 * نفس حجة EventName: نص حر في عمود action يعني أن خطأ إملائياً واحداً
 * يُخرج إجراءً من كل تصفية لاحقة بلا خطأ ولا تحذير — ويصير سجل
 * المساءلة ناقصاً وأنت تظنه كاملاً.
 */
enum AdminAction: string
{
    case ListingApproved = 'listing_approved';
    case ListingRejected = 'listing_rejected';
    case ListingPaused = 'listing_paused';
    case ListingFeatured = 'listing_featured';
    case ListingUnfeatured = 'listing_unfeatured';
    case ListingDeleted = 'listing_deleted';
    case ListingRestored = 'listing_restored';
    case ReportDismissed = 'report_dismissed';
    case ReportActioned = 'report_actioned';
    case QuestionHidden = 'question_hidden';
    case QuestionUnhidden = 'question_unhidden';
    case UserSuspended = 'user_suspended';
    case UserBanned = 'user_banned';
    case UserActivated = 'user_activated';
    case UserRoleChanged = 'user_role_changed';

    /**
     * ختم مراجعة جماعي على ما كان موجوداً قبل وجود اللوحة.
     *
     * حالة منفصلة لا ListingApproved مكرّرة: «اعتمد 160 إعلاناً في نفس
     * الثانية» يقرأه من يفتّش السجل كإشراف صُوريّ. والحقيقة أنها لم
     * تُراجَع بل استُثنيت من الطابور بقرار واحد — وهذا ما يجب أن يقوله
     * السجل بالحرف.
     */
    case ListingsBaselineReviewed = 'listings_baseline_reviewed';

    public function label(): string
    {
        return match ($this) {
            self::ListingApproved => 'اعتماد إعلان',
            self::ListingRejected => 'رفض إعلان',
            self::ListingPaused => 'إيقاف إعلان',
            self::ListingFeatured => 'تمييز إعلان',
            self::ListingUnfeatured => 'إلغاء تمييز',
            self::ListingDeleted => 'حذف إعلان',
            self::ListingRestored => 'استعادة إعلان',
            self::ReportDismissed => 'صرف بلاغ',
            self::ReportActioned => 'تنفيذ بلاغ',
            self::QuestionHidden => 'إخفاء سؤال',
            self::QuestionUnhidden => 'إظهار سؤال',
            self::UserSuspended => 'إيقاف حساب',
            self::UserBanned => 'حظر حساب',
            self::UserActivated => 'تنشيط حساب',
            self::UserRoleChanged => 'تغيير دور',
            self::ListingsBaselineReviewed => 'خطّ أساس للمراجعة',
        };
    }

    /** الإجراءات التي يلزمها سبب مكتوب — كل ما يُضيّق على مستخدم */
    public function requiresReason(): bool
    {
        return in_array($this, [
            self::ListingRejected,
            self::ReportActioned,
            self::QuestionHidden,
            self::UserSuspended,
            self::UserBanned,
        ], true);
    }
}
FILE,

'app/Modules/Admin/Providers/AdminServiceProvider.php' => <<<'FILE'
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
FILE,

'app/Modules/Admin/Console/BaselineReviewedCommand.php' => <<<'FILE'
<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Admin\Services\AuditLogger;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * خطّ أساس لطابور المراجعة.
 *
 * ═══ المشكلة التي يحلّها ═══
 *
 * اللوحة تُفتح فتجد «160 إعلاناً لم تُراجَع». ولا أحد منها ينتظر قراراً
 * فعلاً: كلها موجودة من قبل أن تُوجد اللوحة، ومعظمها من البذور. وطابور
 * يبدأ بمئة وستين عنصراً لا يفرغه أحد — فيتعلّم المشرف أن الرقم زينة،
 * ثم لا يرى الإعلان الحادي والستين بعد المئة وهو الوحيد الذي يحتاجه.
 *
 * الطابور أداة عمل. وقيمته كلها في أنه يُفرَغ.
 *
 * ═══ البديل المرفوض ═══
 *
 * UPDATE listings SET reviewed_at = NOW() من سطر أوامر MySQL. النتيجة
 * نفسها في الجدول، والفرق أن لا أثر لها في admin_actions — فبعد ستة
 * أشهر لا يمكن معرفة هل رُوجعت تلك الإعلانات أم استُثنيت، ومن استثناها.
 * الاختصار الذي يوفّر دقيقة اليوم يُفقد الجواب إلى الأبد.
 *
 * ═══ صفٌّ واحد في السجل لا مئة وستون ═══
 *
 * الفعل واحد: قرار واحد في لحظة واحدة على مجموعة. تسجيله كمئة وستين
 * إجراء يغرق سجل المساءلة ويجعل «آخر الإجراءات» في اللوحة بلا فائدة
 * ليوم كامل. والصفّ الواحد يحمل العدد والتاريخ الفاصل في meta — وهو كل
 * ما يُسأل عنه لاحقاً.
 *
 *     php artisan admin:baseline-reviewed --dry-run
 *     php artisan admin:baseline-reviewed --before=2026-08-11
 */
class BaselineReviewedCommand extends Command
{
    protected $signature = 'admin:baseline-reviewed
                            {--before= : ختم ما أُنشئ قبل هذا التاريخ (Y-m-d) — الافتراضي: الكل}
                            {--dry-run : اعرض العدد ولا تكتب شيئاً}';

    protected $description = 'ختم الإعلانات القائمة كـمراجَعة لتفريغ طابور اللوحة من الضجيج';

    public function handle(AuditLogger $audit): int
    {
        $before = $this->option('before');

        if ($before !== null && Carbon::hasFormat((string) $before, 'Y-m-d') === false) {
            $this->components->error('صيغة --before يجب أن تكون Y-m-d.');

            return self::FAILURE;
        }

        $query = Listing::query()
            ->whereNull('reviewed_at')
            ->when($before !== null, fn ($q) => $q->where('created_at', '<', Carbon::parse($before)->startOfDay()));

        $total = $query->count();

        if ($total === 0) {
            $this->components->info('لا إعلانات بلا مراجعة — الطابور فارغ.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info("{$total} إعلاناً سيُختَم. لم يُكتب شيء (--dry-run).");

            return self::SUCCESS;
        }

        /**
         * reviewed_by يبقى فارغاً بقصد.
         *
         * العمود يعني «المشرف الذي رأى هذا الإعلان». ولم يره أحد. وضع
         * معرّف من شغّل الأمر فيه يكتب في الجدول ادّعاءً لم يحدث — وهو
         * بالضبط ما تتجنّبه بقية المشروع في كل عمود.
         */
        DB::transaction(function () use ($query, $total, $before, $audit): void {
            $query->toBase()->update(['reviewed_at' => now()]);

            $audit->log(null, AdminAction::ListingsBaselineReviewed, null, 'خطّ أساس من الطرفية', [
                'count' => $total,
                'before' => $before,
                'reviewed_by' => null,
                'note' => 'استُثنيت من الطابور، ولم يراجعها بشر',
            ]);
        });

        $this->components->info("{$total} إعلاناً خُتِم كـمراجَع. الطابور الآن يعني الجديد وحده.");
        $this->line('صفّ واحد في admin_actions يحمل العدد — لا صفّ لكل إعلان.');

        return self::SUCCESS;
    }
}
FILE,

'tests/Unit/Shared/DebugLeftoverPatternTest.php' => <<<'FILE'
<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Support\HealthCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * نمط كشف أوامر التصحيح.
 *
 * اختبار وحدة بلا قاعدة بيانات: النمط نصّ، وفحصه لا يحتاج تطبيقاً.
 *
 * وسبب وجوده أن العيب الذي أُصلح كان إنذاراً كاذباً — والإنذار الكاذب
 * لا يُسقط اختباراً ولا يظهر في سجل، بل يظهر كخمسة أسماء ملفات في شاشة
 * يقرأها إنسان ويصدّقها. لا يحرسه إلا هذا.
 */
class DebugLeftoverPatternTest extends TestCase
{
    public static function leftovers(): array
    {
        return [
            'استدعاء مباشر' => ['        dd($listing);'],
            'مع مسافة' => ['        dd ($listing);'],
            'dump' => ['        dump($rows);'],
            'var_dump' => ['        var_dump($payload);'],
            'ray' => ['        ray($user);'],
            'على استعلام' => ['        $query->dump();'],
            'في بداية سطر' => ['dd();'],
        ];
    }

    public static function innocent(): array
    {
        return [
            // هذه بالضبط ما كانت تُبلَّغ خطأً
            'in_array' => ['        return in_array($status, $allowed, true);'],
            'array' => ['        $rows = array();'],
            'add' => ['        $this->add($name, $status, $detail);'],
            'addSelect' => ['        $query->addSelect([$sub]);'],
            'addDays' => ['        $date = now()->addDays(30);'],
            'اسم عمود' => ["        \$table->string('address');"],
            'كلمة عربية بجانب قوس' => ['        // يُضاف(هنا)'],
        ];
    }

    #[Test]
    #[DataProvider('leftovers')]
    public function it_flags_a_real_debug_call(string $line): void
    {
        $this->assertSame(1, preg_match(HealthCheck::DEBUG_PATTERN, $line), $line);
    }

    #[Test]
    #[DataProvider('innocent')]
    public function it_ignores_ordinary_code(string $line): void
    {
        $this->assertSame(0, preg_match(HealthCheck::DEBUG_PATTERN, $line), $line);
    }
}
FILE,

'tests/Feature/Admin/BaselineReviewedTest.php' => <<<'FILE'
<?php

namespace Tests\Feature\Admin;

use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Concerns\MakesAdminUsers;
use Tests\TestCase;

class BaselineReviewedTest extends TestCase
{
    use RefreshDatabase, MakesAdminUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->listing(['reviewed_at' => null]);
        $this->listing(['reviewed_at' => null]);

        $this->artisan('admin:baseline-reviewed', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(2, Listing::query()->whereNull('reviewed_at')->count());
        $this->assertDatabaseCount('admin_actions', 0);
    }

    /** أهم تأكيد: صفٌّ واحد في السجل لا صفّ لكل إعلان */
    public function test_it_stamps_everything_and_logs_once(): void
    {
        collect(range(1, 3))->each(fn () => $this->listing(['reviewed_at' => null]));

        $this->artisan('admin:baseline-reviewed')->assertSuccessful();

        $this->assertSame(0, Listing::query()->whereNull('reviewed_at')->count());
        $this->assertDatabaseCount('admin_actions', 1);

        $row = DB::table('admin_actions')->first();

        $this->assertSame(AdminAction::ListingsBaselineReviewed->value, $row->action);
        $this->assertNull($row->admin_id);
        $this->assertSame(3, json_decode((string) $row->meta, true)['count']);
    }

    /** reviewed_by يبقى فارغاً: لم يرَ الإعلان بشر، والعمود لا يكذب */
    public function test_it_never_claims_a_human_reviewer(): void
    {
        $listing = $this->listing(['reviewed_at' => null]);

        $this->artisan('admin:baseline-reviewed')->assertSuccessful();

        $listing->refresh();

        $this->assertNotNull($listing->reviewed_at);
        $this->assertNull($listing->reviewed_by);
    }

    public function test_before_limits_the_stamp(): void
    {
        $old = $this->listing(['reviewed_at' => null]);
        $old->forceFill(['created_at' => now()->subMonth()])->save();

        $fresh = $this->listing(['reviewed_at' => null]);

        $this->artisan('admin:baseline-reviewed', ['--before' => now()->subWeek()->toDateString()])
            ->assertSuccessful();

        $this->assertNotNull($old->fresh()->reviewed_at);
        $this->assertNull($fresh->fresh()->reviewed_at);
    }

    public function test_a_bad_date_fails_loudly(): void
    {
        $this->artisan('admin:baseline-reviewed', ['--before' => '11-08-2026'])->assertFailed();
    }
}
FILE,

];

$written = 0;
$replaced = 0;

foreach ($files as $relative => $contents) {
    $path = $root . '/' . $relative;
    $dir = dirname($path);

    if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
        fwrite(STDERR, "تعذّر إنشاء المجلد: {$dir}\n");
        exit(1);
    }

    $exists = file_exists($path);
    $normalized = str_replace("\r\n", "\n", rtrim($contents, "\n")) . "\n";

    if (file_put_contents($path, $normalized) === false) {
        fwrite(STDERR, "تعذّرت الكتابة: {$relative}\n");
        exit(1);
    }

    $exists ? $replaced++ : $written++;

    echo ($exists ? '~ ' : '+ ') . $relative . "\n";
}

echo "\n{$written} ملفاً جديداً، {$replaced} مستبدلاً.\n";

echo <<<'NEXT'

الخطوات:

  1. php artisan optimize:clear
  2. php artisan test --filter="DebugLeftover|BaselineReviewed"
  3. php artisan admin:baseline-reviewed --dry-run
  4. php artisan admin:baseline-reviewed
  5. حدّث /admin — الطابور يصير صفراً، و«أوامر تصحيح منسية» تصير نظيفاً

تحذيران في لوحتك ليسا عيبين، ولا يُلمسان:

  · «أحداث معرَّفة بلا استخدام: favorite_added، favorite_removed،
    listing_reported» — صحيح ومؤقّت. الحالات أُضيفت أمس ولم يضغط أحد زرّ
    المفضلة ولم يُرسل بلاغ بعدها. اضغط ★ على أي إعلان وأرسل بلاغاً
    واحداً، ثم حدّث الصفحة: يختفي التحذير من نفسه. لو بقي بعد ذلك فالعيب
    في التسجيل لا في الفحص — أخبرني.
  · «سائق الطابور — database» — تحذير معلومات لا خطأ. الأحداث لا تُفقد
    إن توقّف queue:work: تنتظر في جدول jobs حتى يعمل. الخطر الحقيقي
    الوحيد أن تشغّل analytics:rollup قبل العامل، فيُجمَّع يومٌ ناقص —
    فشغّل العامل أولاً دائماً. وفي التطوير وحده يمكنك QUEUE_CONNECTION=sync
    في .env فتُكتب الأحداث فوراً بلا عامل.

NEXT;