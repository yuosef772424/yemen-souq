<?php

namespace App\Modules\Merchant\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * أرقام التاجر عن إعلاناته، والإشارات المستخرجة منها.
 *
 * ═══ الفرق عن ListingStatsReader ═══
 *
 * ذاك يجيب «كيف حال هذا الإعلان». وهذا يجيب سؤالاً آخر تماماً: «أيّ
 * إعلاناتي يحتاجني الآن». الأول يُفتح بعد أن يشك التاجر في إعلان، والثاني
 * هو ما يخبره أيّها يشك فيه. ولهذا لا يُدمجان: دمجهما ينتج شاشة تعرض كل
 * شيء ولا تقول شيئاً.
 *
 * ═══ القراءة من الجداول المجمّعة وحدها ═══
 *
 * نفس قاعدة الوحدة: user_events لا يُستعلَم في طلب HTTP. والنتيجة
 * الملازمة: إن لم يُشغَّل analytics:rollup تكون الأرقام أصفاراً بينما
 * listings.views_count بالمئات. لذلك lastRolledUp يُرجَع دائماً ويُعرض —
 * صفر لا يقول سببه يجعل التاجر يظن أن أحداً لم يرَ إعلانه.
 *
 * ═══ ولماذا «إشارات» لا مجرد أرقام ═══
 *
 * «1,240 مشاهدة» رقم يُسعِد ولا يُفعِل شيئاً. «ظهر 300 مرة ونُقر 4» جملة
 * تقول للتاجر: العنوان أو الصورة الأولى أو السعر يصدّ في النتائج. الشاشة
 * التي تعرض الأول فقط تُقرأ مرة وتُنسى.
 */
final class MerchantInsights
{
    public const DEFAULT_DAYS = 30;

    /** حدود الإشارات — منخفضة بقصد لسوق صغير، ومرفوعة عن الضجيج */
    private const MIN_IMPRESSIONS = 30;

    private const WEAK_CTR = 2.0;

    private const MIN_VIEWS_FOR_CONTACT = 30;

    private const EXPIRY_WARNING_DAYS = 3;

    /**
     * صفٌّ لكل إعلان مع مجاميع النافذة.
     *
     * استعلام واحد بربط أيسر لا استعلام لكل إعلان: تاجر بأربعين إعلاناً
     * يعني أربعين رحلة إلى القاعدة لرسم جدول واحد. والربط الأيسر ضروري لا
     * تجميلي — الإعلان الذي لا صفوف تجميع له يجب أن يظهر بأصفار لا أن
     * يغيب، وغيابه يجعل التاجر يظن أنه حُذف.
     */
    public function perListing(User $user, int $days = self::DEFAULT_DAYS): Collection
    {
        $from = Carbon::today()->subDays(max(1, $days) - 1)->toDateString();

        return DB::table('listings')
            ->leftJoin('listing_daily_stats', function ($join) use ($from): void {
                $join->on('listing_daily_stats.listing_id', '=', 'listings.id')
                    ->where('listing_daily_stats.stat_date', '>=', $from);
            })
            ->where('listings.user_id', $user->id)
            ->whereNull('listings.deleted_at')
            ->groupBy('listings.id', 'listings.title', 'listings.slug', 'listings.status', 'listings.expires_at', 'listings.favorites_count')
            ->selectRaw('listings.id, listings.title, listings.slug, listings.status, listings.expires_at')
            ->selectRaw('listings.favorites_count as favorites_total')
            ->selectRaw('COALESCE(SUM(listing_daily_stats.views), 0) as views')
            ->selectRaw('COALESCE(SUM(listing_daily_stats.unique_viewers), 0) as unique_viewers')
            ->selectRaw('COALESCE(SUM(listing_daily_stats.contacts), 0) as contacts')
            ->selectRaw('COALESCE(SUM(listing_daily_stats.favorites), 0) as favorites')
            ->selectRaw('COALESCE(SUM(listing_daily_stats.search_impressions), 0) as impressions')
            ->selectRaw('COALESCE(SUM(listing_daily_stats.search_clicks), 0) as clicks')
            ->orderByDesc('views')
            ->get()
            ->map(fn (object $row): object => $this->decorate($row));
    }

    /** @return array<string, int> */
    public function totals(Collection $rows): array
    {
        return [
            'listings' => $rows->count(),
            'views' => (int) $rows->sum('views'),
            'unique_viewers' => (int) $rows->sum('unique_viewers'),
            'contacts' => (int) $rows->sum('contacts'),
            'impressions' => (int) $rows->sum('impressions'),
            'clicks' => (int) $rows->sum('clicks'),
            'favorites' => (int) $rows->sum('favorites_total'),
        ];
    }

    /**
     * الإشارات: ما يحتاج فعلاً من التاجر.
     *
     * كل إشارة تحمل سبباً وفعلاً مقترحاً. وترتيبها ليس عشوائياً: ما
     * يُخسِر مشترياً موجوداً الآن قبل ما يُحسّن الظهور لاحقاً — السؤال
     * بلا جواب مشترٍ ينتظر، وضعف النقر فرصة لم تبدأ.
     *
     * @return array<int, array{listing: object, kind: string, title: string, detail: string}>
     */
    public function signals(User $user, Collection $rows): array
    {
        $unanswered = $this->unansweredQuestionsPerListing($user);
        $signals = [];

        foreach ($rows as $row) {
            $pending = (int) ($unanswered[$row->id] ?? 0);

            if ($pending > 0) {
                $signals[] = [
                    'listing' => $row,
                    'kind' => 'urgent',
                    'title' => "{$pending} سؤال بلا جواب",
                    'detail' => 'السؤال المعلّق يقرأه كل زائر لاحق كإشارة إلى بائع لا يردّ.',
                ];
            }
        }

        foreach ($rows as $row) {
            if ($row->status !== 'active') {
                continue;
            }

            if ($row->views >= self::MIN_VIEWS_FOR_CONTACT && $row->contacts === 0) {
                $signals[] = [
                    'listing' => $row,
                    'kind' => 'attention',
                    'title' => 'يُشاهَد ولا يُتواصَل',
                    'detail' => "{$row->views} مشاهدة وصفر طلب رقم. الصفحة تُقرأ ولا تُقنع — راجع السعر والصور والوصف.",
                ];
            }

            if ($row->impressions >= self::MIN_IMPRESSIONS && $row->ctr !== null && $row->ctr < self::WEAK_CTR) {
                $signals[] = [
                    'listing' => $row,
                    'kind' => 'attention',
                    'title' => 'يظهر في البحث ولا يُنقر',
                    'detail' => "ظهر {$row->impressions} مرة ونُقر {$row->clicks}. ما يُرى في النتائج هو العنوان والصورة الأولى والسعر.",
                ];
            }

            if ($row->days_left !== null && $row->days_left <= self::EXPIRY_WARNING_DAYS && $row->views > 0) {
                $signals[] = [
                    'listing' => $row,
                    'kind' => 'urgent',
                    'title' => 'ينتهي قريباً',
                    'detail' => "يتبقى {$row->days_left} يوماً، وعليه {$row->views} مشاهدة في هذه النافذة. التجديد يمدّه ثلاثين يوماً.",
                ];
            }
        }

        // العاجل أولاً، وبترتيب ثابت داخل كل درجة
        usort($signals, fn (array $a, array $b): int => ($a['kind'] === 'urgent' ? 0 : 1) <=> ($b['kind'] === 'urgent' ? 0 : 1));

        return $signals;
    }

    public function lastRolledUp(): ?string
    {
        $value = DB::table('listing_daily_stats')->max('stat_date');

        return $value === null ? null : Carbon::parse($value)->toDateString();
    }

    /** @return array<int, int> */
    private function unansweredQuestionsPerListing(User $user): array
    {
        return DB::table('listing_questions')
            ->join('listings', 'listings.id', '=', 'listing_questions.listing_id')
            ->where('listings.user_id', $user->id)
            ->whereNull('listing_questions.answered_at')
            ->whereNull('listing_questions.hidden_at')
            ->groupBy('listing_questions.listing_id')
            ->selectRaw('listing_questions.listing_id as listing_id, COUNT(*) as total')
            ->pluck('total', 'listing_id')
            ->all();
    }

    /**
     * الأعمدة المحسوبة تُضاف هنا لا في القالب.
     *
     * ونسبة النقر تبقى null تحت الحدّ — بنفس حجة ListingStatsReader:
     * ظهور واحد ونقرة واحدة تعطي 100% وهو رقم يوهم بنجاح لا وجود له.
     */
    private function decorate(object $row): object
    {
        $row->views = (int) $row->views;
        $row->unique_viewers = (int) $row->unique_viewers;
        $row->contacts = (int) $row->contacts;
        $row->favorites = (int) $row->favorites;
        $row->favorites_total = (int) $row->favorites_total;
        $row->impressions = (int) $row->impressions;
        $row->clicks = (int) $row->clicks;

        $row->ctr = $row->impressions >= 20
            ? round($row->clicks / $row->impressions * 100, 1)
            : null;

        $row->days_left = $row->expires_at === null
            ? null
            : (int) round(Carbon::today()->diffInDays(Carbon::parse($row->expires_at)->startOfDay(), false));

        return $row;
    }
}
