<?php

namespace App\Modules\Catalog\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * قراءة «إعلاناتي»: التبويبات والعدّادات والترقيم — مرة واحدة لوجهَي اللوحة.
 *
 * كان هذا الكود منسوخاً حرفياً في `AccountListingController` (الويب) و
 * `MyListingController` (الـAPI). وهو ليس شكل استجابة بل **تعريف**: ما هي
 * «المنتهية»، وما هي «المعروضة»، وكيف تُحسب العدّادات. تعريفٌ في موضعين
 * يتباعد بأول تعديل — فتُظهر الشاشة رقماً ويُظهر التطبيق غيره لنفس المعلن.
 *
 * ولا يعرف شيئاً عن الطلب ولا الاستجابة: يستقبل مستخدماً وتبويباً وحجم
 * صفحة، ويُرجع بيانات. وحجم الصفحة يقرّره المتحكّم (12 في الويب، وما يطلبه
 * العميل في الـAPI).
 */
final class OwnerListings
{
    /**
     * التبويبات: ما يمكن للنظام أن ينتجه فعلاً، لا كل قيم عمود status.
     *
     * و«مباعة» تبويب لا حالة مخفية: الإعلان المباع يخرج من «المعروضة»
     * ومن نتائج البحث معاً، فبلا تبويب له يختفي من كل مكان — ولا يجد
     * المعلن طريقاً للتراجع عن نقرة خاطئة.
     */
    public const TABS = ['live', 'sold', 'expired', 'trashed', 'all'];

    public const DEFAULT_TAB = 'live';

    /** قيمة غير معروفة (أو غير نصّية) ترجع إلى «المعروضة» بدل أن تفشل */
    public function resolveTab(?string $requested): string
    {
        return in_array($requested, self::TABS, true) ? $requested : self::DEFAULT_TAB;
    }

    /**
     * العدّادات في استعلامين لا خمسة.
     *
     * استعلام لكل تبويب يعني خمس رحلات إلى القاعدة لرسم أربعة أرقام في
     * أعلى الصفحة. والاستعلام الثاني ضروري لا زائد: الحذف المنطقي يُقصي
     * الصفوف المحذوفة من الاستعلام الأول بأثر SoftDeletes، فلا يمكن
     * عدّها فيه.
     *
     * و«المنتهية» تُحسب من `expires_at` لا من `status = 'expired'`: لا شيء
     * في النظام يكتب تلك الحالة (وهذا مقصود — انظر `scopeNotExpired`)،
     * فتبويب يعتمد عليها يبقى صفراً بينما الإعلانات تنتهي فعلاً.
     *
     * @return array{live: int, sold: int, expired: int, trashed: int, all: int}
     */
    public function counts(User $user): array
    {
        $now = now()->toDateTimeString();

        $row = Listing::query()
            ->ownedBy($user)
            ->selectRaw('COUNT(*) as all_count')
            ->selectRaw(
                "SUM(CASE WHEN status = 'active' AND (expires_at IS NULL OR expires_at >= ?) THEN 1 ELSE 0 END) as live_count",
                [$now]
            )
            ->selectRaw(
                'SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < ? THEN 1 ELSE 0 END) as expired_count',
                [$now]
            )
            ->selectRaw("SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_count")
            ->first();

        return [
            'live' => (int) ($row->live_count ?? 0),
            'sold' => (int) ($row->sold_count ?? 0),
            'expired' => (int) ($row->expired_count ?? 0),
            'trashed' => Listing::onlyTrashed()->ownedBy($user)->count(),
            'all' => (int) ($row->all_count ?? 0),
        ];
    }

    public function paginate(User $user, string $tab, int $perPage): LengthAwarePaginator
    {
        $query = Listing::query()
            ->ownedBy($user)
            ->with(['images', 'currency', 'category', 'location']);

        match ($tab) {
            'live' => $query->active()->notExpired(),
            'sold' => $query->where('status', 'sold'),
            'expired' => $query->expired(),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };

        return $query
            ->orderByDesc($tab === 'trashed' ? 'deleted_at' : 'created_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
