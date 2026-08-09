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
     */
    private function checkDebugLeftovers(): void
    {
        $needles = ['d' . 'd(', 'dump(', 'var_' . 'dump(', 'ray('];
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'HealthCheck.php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $found[] = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());

                    break;
                }
            }
        }

        $this->add(
            'أوامر تصحيح منسية',
            $found === [] ? 'ok' : 'warn',
            $found === [] ? 'نظيف' : implode('، ', array_slice($found, 0, 5))
        );
    }

    private function add(string $name, string $status, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
    }
}