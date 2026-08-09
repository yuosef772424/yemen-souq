<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\RenewalOutcome;
use App\Modules\Catalog\Models\Listing;

/**
 * تجديد الإعلان: تمديد expires_at ثلاثين يوماً.
 *
 * ثلاثة قرارات تستحق التوضيح:
 *
 * 1. published_at لا يُلمس إطلاقاً.
 *    وهذا أهم سطر في الملف. published_at هو ما يُرتَّب به «الأحدث» وما
 *    يُعرض كـ«منذ ثلاثة أيام». لو حرّكه التجديد، لصار زر التجديد ترقيةً
 *    مجانية تُقفز بالإعلان إلى أعلى النتائج كل ثلاثين يوماً — ويفقد
 *    ترتيب «الأحدث» معناه تماماً، ويصبح إعلان عمره سنة يبدو جديداً.
 *    البديل المرفوض: تحديث published_at ليبدو الإعلان نشطاً. مرفوض
 *    لأنه يكسب المعلن على حساب المشتري، وهذا بالضبط ما تفعله المنصات
 *    التي تُشتكى منها.
 *
 * 2. نافذة سبعة أيام.
 *    البديل المرفوض: السماح بالتجديد في أي وقت. يبدو أكثر تسامحاً، لكن
 *    ضغطة على إعلان يتبقى له 28 يوماً لا تفعل شيئاً مرئياً — فيظن
 *    المعلن أن الزر معطوب. القيد يجعل الزر يعني شيئاً كلما ظهر.
 *
 * 3. التمديد = الآن + 30 يوماً، لا expires_at + 30.
 *    داخل النافذة الفارق أربعة أيام على الأكثر، والصيغة الأولى مفهومة
 *    للمعلن بلا حساب: «إعلانك يعمل حتى تاريخ كذا».
 */
final class ListingRenewalService
{
    public const EXTENSION_DAYS = 30;

    public const WINDOW_DAYS = 7;

    /** الحالات التي يمكن للتجديد أن يُعيدها إلى العمل */
    private const RENEWABLE_STATUSES = ['active', 'paused', 'expired'];

    public function renew(Listing $listing): RenewalOutcome
    {
        if (! in_array($listing->status, self::RENEWABLE_STATUSES, true)) {
            return RenewalOutcome::NotRenewable;
        }

        if (! $this->insideWindow($listing)) {
            return RenewalOutcome::TooEarly;
        }

        // forceFill لا update: العمودان خارج fillable بقصد — انظر الموديل
        $listing->forceFill([
            'status' => 'active',
            'expires_at' => now()->addDays(self::EXTENSION_DAYS),
            'renewed_at' => now(),
            'renewals_count' => (int) $listing->renewals_count + 1,
        ])->save();

        return RenewalOutcome::Renewed;
    }

    /**
     * إعلان بلا تاريخ انتهاء قابل للتجديد دائماً: غياب التاريخ يعني
     * بياناً قديماً أو مستورداً، ومنعه من التجديد يترك المعلن بلا مخرج.
     */
    public function insideWindow(Listing $listing): bool
    {
        if ($listing->expires_at === null) {
            return true;
        }

        return $listing->expires_at->lessThanOrEqualTo(now()->addDays(self::WINDOW_DAYS));
    }

    public function canRenew(Listing $listing): bool
    {
        return in_array($listing->status, self::RENEWABLE_STATUSES, true)
            && $this->insideWindow($listing);
    }
}