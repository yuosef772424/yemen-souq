<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingSaleMarker;

/**
 * زرّ واحد بمسارين حسب الحالة في قاعدة البيانات.
 *
 * لماذا لا مساران منفصلان: الزرّ في الواجهة واحد يتبدّل نصّه، ومساران
 * يعنيان أن الواجهة تقرّر أيّهما تستدعي — فتُرسل «بِع» على إعلان مباع
 * إن تأخّر تحديث الشاشة. القرار هنا مبنيّ على الحالة في القاعدة لا على
 * ما رآه العميل.
 *
 * يُرجع `$wasSold` و`$done` فقط: الرسائل ووجهة الاستجابة تخصّ كل واجهة
 * (نصّ الويب يختم بـ«يمكنك التراجع من هنا»، والـAPI يُرجع `code`).
 */
final class ToggleListingSold
{
    public function __construct(
        private readonly ListingSaleMarker $sales,
    ) {}

    /**
     * @return array{wasSold: bool, done: bool}
     */
    public function __invoke(Listing $listing): array
    {
        $wasSold = $listing->status === 'sold';

        $done = $wasSold
            ? $this->sales->unmarkSold($listing)
            : $this->sales->markSold($listing);

        return ['wasSold' => $wasSold, 'done' => $done];
    }
}
