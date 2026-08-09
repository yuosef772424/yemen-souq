<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Listing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * أرقام المشاهدات المعروضة للمعلن.
 *
 * القرار المركزي: رقمان من مصدرين، وكلٌّ يُعرض بما هو.
 *
 *   listings.views_count      عدّاد فوري يُزاد مع كل زيارة. صحيح دائماً.
 *   listing_daily_stats       تجميع يوميّ يكتبه analytics:rollup.
 *
 * التفصيل اليومي لا يمكن أن يأتي من العدّاد (عدد واحد بلا تاريخ)، ولا
 * من user_events مباشرة (سجل خام ينمو بلا حد — استعلامه في طلب HTTP
 * يبطؤ خطياً حتى يتوقف؛ وهذه قاعدة الوحدة المعلنة أصلاً).
 *
 * والمصيدة التي يجب ألّا تُخفى: إن لم يُشغَّل analytics:rollup، يبقى
 * الجدول اليومي فارغاً بينما views_count بالمئات. جدول أصفار بلا
 * تفسير يجعل المعلن يظن أن إعلانه لم يُرَ. لهذا نُرجع lastRolledUp
 * ونعرضه صريحاً في الصفحة — الفراغ يجب أن يقول سببه.
 */
final class ListingStatsReader
{
    public const DEFAULT_DAYS = 14;

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     peak: int,
     *     lastRolledUp: string|null
     * }
     */
    public function forListing(Listing $listing, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, min(90, $days));
        $from = Carbon::today()->subDays($days - 1);

        $stored = DB::table('listing_daily_stats')
            ->where('listing_id', $listing->id)
            ->whereBetween('stat_date', [$from->toDateString(), Carbon::today()->toDateString()])
            ->get()
            ->keyBy(fn (object $row): string => Carbon::parse($row->stat_date)->toDateString());

        $rows = [];
        $totals = [
            'views' => 0, 'unique_viewers' => 0, 'contacts' => 0,
            'search_impressions' => 0, 'search_clicks' => 0,
        ];

        // الأيام الغائبة تُملأ بأصفار: صفّ ناقص في الجدول يعني «لا حدث
        // في ذلك اليوم»، وحذف اليوم من العرض يجعل الفراغ يبدو كأنه لم يكن
        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo(Carbon::today()); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $row = $stored->get($date);

            $day = [
                'date' => $date,
                'views' => (int) ($row->views ?? 0),
                'unique_viewers' => (int) ($row->unique_viewers ?? 0),
                'contacts' => (int) ($row->contacts ?? 0),
                'search_impressions' => (int) ($row->search_impressions ?? 0),
                'search_clicks' => (int) ($row->search_clicks ?? 0),
            ];

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $day[$key];
            }

            $rows[] = $day;
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'peak' => max(1, max(array_column($rows, 'views'))),
            'lastRolledUp' => $this->lastRolledUp(),
        ];
    }

    /**
     * نسبة النقر من الظهور.
     *
     * تُحسب فقط عند ظهور كافٍ. البديل المرفوض: عرض النسبة دائماً —
     * ظهور واحد ونقرة واحدة يعطيان 100%، وهو رقم يوهم المعلن بنجاح
     * لا وجود له. عشرون ظهوراً حدّ أدنى متحفّظ لكنه صادق.
     */
    public function clickRate(int $impressions, int $clicks): ?float
    {
        if ($impressions < 20) {
            return null;
        }

        return round($clicks / $impressions * 100, 1);
    }

    /** آخر يوم يحمل تجميعاً — للتاريخ لا لهذا الإعلان: يخبر عن حال المجدول */
    private function lastRolledUp(): ?string
    {
        $value = DB::table('listing_daily_stats')->max('stat_date');

        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}