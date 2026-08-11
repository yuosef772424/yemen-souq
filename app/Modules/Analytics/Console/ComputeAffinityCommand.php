<?php

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Events\EventName;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * حساب user_category_affinity.
 *
 * ═══ لماذا وُجد ═══
 *
 * الجدول موجود من أول هجرة، وتعليق العمود score يصف المعادلة بالحرف
 * («مشاهدة=1، مفضلة=5، تواصل=10، مع تلاشٍ زمني») — ولا كود في المشروع
 * يكتب فيه صفاً. جدول فارغ بمعادلة موصوفة هو أسوأ من جدول غير موجود:
 * من يقرأ المخطط يظن الميزة قائمة.
 *
 * ═══ التلاشي الزمني، والبديل المرفوض ═══
 *
 * البديل المرفوض: مجموع الأوزان بلا تلاشٍ. مرفوض لأن من تصفّح السيارات
 * شهرين ثم انتقل إلى الأثاث يبقى «مهتماً بالسيارات» إلى الأبد — فيصير
 * التخصيص نصباً تذكارياً لاهتمام انتهى. نصف العمر 21 يوماً: سلوك اليوم
 * يزن ضعف سلوك ما قبل ثلاثة أسابيع.
 *
 * ═══ ولماذا الحساب في PHP لا في SQL ═══
 *
 * POW وDATEDIFF تختلف بين MySQL وSQLite، والاختبارات تجري على محرك قد
 * يخالف الإنتاج. الاستعلام يجمّع (مستخدم، تصنيف، يوم، حدث) — وهو تجميع
 * يقلّص السجل الخام إلى صفوف قليلة — والتلاشي يُحسب على تلك الصفوف.
 * كلفة الحساب في PHP لا شيء بعد التجميع، والنتيجة واحدة على أي محرك.
 *
 * ═══ وما لا يفعله ═══
 *
 * لا يورّث الدرجة إلى التصنيف الأب. مشاهدة «آيفون» لا تُقوّي «إلكترونيات»
 * كلها. الوراثة تحتاج قراراً في نسبة التسريب بين المستويات، وأي رقم
 * أختاره الآن رقم مُختلَق — والوحدة قائمة على تجنّب ذلك بالضبط.
 *
 * التشغيل: php artisan analytics:affinity [--days=90]
 */
class ComputeAffinityCommand extends Command
{
    protected $signature = 'analytics:affinity
                            {--days=90 : نافذة السلوك المحسوبة}';

    protected $description = 'حساب ميل المستخدمين إلى التصنيفات من سلوكهم';

    /** نصف العمر بالأيام */
    public const HALF_LIFE_DAYS = 21;

    /** أوزان الأحداث — كما في تعليق عمود score */
    private const WEIGHTS = [
        'listing_viewed' => 1.0,
        'favorite_added' => 5.0,
        'contact_revealed' => 10.0,
    ];

    public function handle(): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $from = Carbon::today()->subDays($days - 1);
        $today = Carbon::today();

        $rows = DB::table('user_events')
            ->join('listings', 'listings.id', '=', 'user_events.entity_id')
            ->whereNotNull('user_events.user_id')
            ->where('user_events.entity_type', 'listing')
            ->whereIn('user_events.event_name', array_keys(self::WEIGHTS))
            ->where('user_events.occurred_at', '>=', $from->startOfDay())
            ->selectRaw('user_events.user_id as user_id')
            ->selectRaw('listings.category_id as category_id')
            ->selectRaw('DATE(user_events.occurred_at) as day')
            ->selectRaw('user_events.event_name as event_name')
            ->selectRaw('COUNT(*) as hits')
            ->groupBy('user_events.user_id', 'listings.category_id', 'day', 'user_events.event_name')
            ->get();

        if ($rows->isEmpty()) {
            $this->components->warn('لا أحداث سلوك في النافذة — لا شيء ليُحسَب.');

            return self::SUCCESS;
        }

        /** @var array<string, array{user_id:int, category_id:int, score:float, last:string}> $scores */
        $scores = [];

        foreach ($rows as $row) {
            $weight = self::WEIGHTS[$row->event_name] ?? 0.0;

            if ($weight === 0.0 || $row->category_id === null) {
                continue;
            }

            $age = $today->diffInDays(Carbon::parse($row->day), true);
            $decay = 2 ** (-$age / self::HALF_LIFE_DAYS);

            $key = $row->user_id . ':' . $row->category_id;

            $scores[$key] ??= [
                'user_id' => (int) $row->user_id,
                'category_id' => (int) $row->category_id,
                'score' => 0.0,
                'last' => (string) $row->day,
            ];

            $scores[$key]['score'] += $weight * $row->hits * $decay;
            $scores[$key]['last'] = max($scores[$key]['last'], (string) $row->day);
        }

        /**
         * الجدول يُفرَّغ ثم يُكتب، لا يُحدَّث تراكمياً.
         *
         * الدرجة دالة على النافذة كلها: صفٌّ قديم لمستخدم توقّف نشاطه
         * يجب أن يختفي لا أن يبقى بقيمته الأخيرة. والتحديث التراكمي
         * يجعل الجدول أرشيفاً لا صورة للحال.
         *
         * ═══ delete لا truncate — وهذا عيب أُصلح ═══
         *
         * TRUNCATE في MySQL أمر DDL، وأوامر DDL تُثبّت المعاملة الجارية
         * ضمنياً (implicit commit). فحين ينتهي الإغلاق ويحاول لارافل
         * commit لا تكون هناك معاملة، فيسقط الأمر بـ
         * «PDOException: There is no active transaction» — بعد أن مُحي
         * الجدول فعلاً. أي أن الفشل يترك الجدول فارغاً: أسوأ نتيجة
         * ممكنة من الحالتين.
         *
         * DELETE أمر DML يخضع للمعاملة كاملاً. أبطأ على جدول ضخم ولا
         * يصفّر AUTO_INCREMENT — والجدول هنا بلا عمود تعريف تلقائي
         * (مفتاحه مركّب)، فلا شيء يُصفَّر أصلاً.
         */
        DB::transaction(function () use ($scores): void {
            DB::table('user_category_affinity')->delete();

            foreach (array_chunk(array_values($scores), 500) as $chunk) {
                DB::table('user_category_affinity')->insert(array_map(fn (array $row): array => [
                    'user_id' => $row['user_id'],
                    'category_id' => $row['category_id'],
                    'score' => round($row['score'], 4),
                    'last_event_at' => $row['last'] . ' 00:00:00',
                    'computed_at' => now(),
                ], $chunk));
            }
        });

        $this->components->info(count($scores) . ' صفَّ ميل مبنيّ من ' . $rows->count() . ' صفَّ سلوك مجمَّع.');

        // إشارة على أن الأوزان تقرأ أحداثاً موجودة فعلاً — انظر HealthCheck
        $missing = array_values(array_diff(
            array_keys(self::WEIGHTS),
            DB::table('user_events')->distinct()->pluck('event_name')->all()
        ));

        if ($missing !== []) {
            $this->components->warn('أحداث بوزن ولا وجود لها في السجل: ' . implode('، ', $missing));
        }

        return self::SUCCESS;
    }
}
