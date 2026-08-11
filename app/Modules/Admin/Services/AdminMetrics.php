<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * أرقام لوحة القيادة.
 *
 * ═══ القاعدة المتبعة: لا استعلام واحد على user_events ═══
 *
 * وهي نفس قاعدة الوحدة المعلنة في RollupCommand وListingStatsReader.
 * السجل الخام ينمو بلا حد، ولوحة تحكم تستعلمه مباشرة تعمل بسرعة اليوم
 * وتصير غير قابلة للفتح بعد ستة أشهر — وهذا أسوأ أنواع البطء لأنه
 * يظهر بعد أن يعتمد عليها أحد.
 *
 * ═══ التمييز بين طابور عمل وعدّاد ═══
 *
 * الأرقام في queue() كلها قابلة للتصفير بعمل يدوي: إعلان ينتظر مراجعة،
 * بلاغ مفتوح، سؤال بلا جواب. والأرقام في overview() وصف حال لا يُصفَّر.
 * الخلط بينهما في شاشة واحدة يجعل المشرف يرى «1,240» فلا يعرف هل عليه
 * فعل شيء. طابور العمل أولاً في الصفحة بقصد.
 *
 * ═══ lastRolledUp معروض دائماً ═══
 *
 * إن لم يُشغَّل analytics:rollup تبقى جداول التجميع فارغة، فتعرض اللوحة
 * أصفاراً لمنصة فيها حركة. الصفر الذي لا يقول سببه كذبة.
 */
final class AdminMetrics
{
    /** طابور العمل — ما ينتظر قراراً بشرياً */
    public function queue(): array
    {
        return [
            'unreviewed' => Listing::query()->whereNull('reviewed_at')->count(),
            'reports' => DB::table('listing_reports')->whereIn('status', ['open', 'reviewing'])->count(),
            'unanswered' => DB::table('listing_questions')
                ->whereNull('answered_at')->whereNull('hidden_at')->count(),
            'rejected' => Listing::query()->where('status', 'rejected')->count(),
        ];
    }

    /**
     * حال المنصة.
     *
     * استعلام واحد مجمَّع لحالات الإعلانات بدل استعلام لكل حالة: سبع
     * حالات في العمود تعني سبع رحلات إلى القاعدة لرسم صفٍّ من الأرقام.
     */
    public function overview(): array
    {
        $statuses = Listing::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $today = Carbon::today();

        return [
            'listings_total' => array_sum($statuses),
            'listings_by_status' => $statuses,
            'listings_today' => Listing::query()->where('created_at', '>=', $today)->count(),
            'listings_trashed' => Listing::onlyTrashed()->count(),
            'listings_featured' => Listing::query()
                ->where('is_featured', true)
                ->where(fn ($q) => $q->whereNull('featured_until')->orWhere('featured_until', '>=', now()))
                ->count(),
            'users_total' => User::query()->count(),
            'users_today' => User::query()->where('created_at', '>=', $today)->count(),
            'users_blocked' => User::query()->whereIn('status', ['suspended', 'banned'])->count(),
        ];
    }

    /**
     * القمع لآخر أيام — من funnel_daily وحده.
     *
     * @return array<int, array<string, mixed>>
     */
    public function funnel(int $days = 7): array
    {
        $from = Carbon::today()->subDays(max(1, $days) - 1);

        $rows = DB::table('funnel_daily')
            ->where('stat_date', '>=', $from->toDateString())
            ->orderBy('stat_date')
            ->get()
            ->keyBy(fn (object $row): string => Carbon::parse($row->stat_date)->toDateString());

        $out = [];

        // الأيام الغائبة تُملأ بأصفار — يوم محذوف من الرسم يخفي انقطاعاً
        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo(Carbon::today()); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $row = $rows->get($date);

            $out[] = [
                'date' => $date,
                'searches' => (int) ($row->searches ?? 0),
                'searches_with_click' => (int) ($row->searches_with_click ?? 0),
                'listing_views' => (int) ($row->listing_views ?? 0),
                'contacts_revealed' => (int) ($row->contacts_revealed ?? 0),
                'listings_created' => (int) ($row->listings_created ?? 0),
            ];
        }

        return $out;
    }

    /**
     * أعلى عبارات البحث بلا نتائج.
     *
     * هذا أنفع رقم في اللوحة كلها ولهذا هو فيها: كل صف هنا طلبٌ موجود
     * في السوق ولا عرض له عندنا — إما نقص في المخزون أو عيب في البحث،
     * وكلاهما قرار عمل لا رقم للتزيين.
     */
    public function zeroResultQueries(int $days = 14, int $limit = 8): array
    {
        return DB::table('search_query_stats')
            ->selectRaw('query_normalized, SUM(zero_result_count) as zeros, SUM(times_searched) as searches')
            ->where('stat_date', '>=', Carbon::today()->subDays($days)->toDateString())
            ->having('zeros', '>', 0)
            ->groupBy('query_normalized')
            ->orderByDesc('zeros')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'query' => $row->query_normalized,
                'zeros' => (int) $row->zeros,
                'searches' => (int) $row->searches,
            ])
            ->all();
    }

    /** آخر يوم فيه تجميع — يخبر عن حال المجدول لا عن حال المنصة */
    public function lastRolledUp(): ?string
    {
        $value = DB::table('funnel_daily')->max('stat_date')
            ?? DB::table('listing_daily_stats')->max('stat_date');

        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
