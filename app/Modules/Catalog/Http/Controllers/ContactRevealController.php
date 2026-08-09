<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Http\JsonResponse;

/**
 * كشف رقم التواصل.
 *
 * لماذا مسار خادم لا زر محلي بـAlpine فقط: كشف الرقم هو أقوى إشارة
 * نية شراء في المنصة — أقوى من المشاهدة وأقوى من الإضافة للمفضلة.
 * إخفاؤه في المتصفح يعني فقدان أهم مُدخل لحساب جودة الإعلان.
 *
 * الرقم لا يُرسَل في القالب أصلاً؛ يُجلب من هنا. هذا يقلّل أيضاً
 * حصاد الأرقام آلياً من صفحات الإعلانات.
 */
class ContactRevealController
{
    public function __construct(
        private readonly EventRecorder $events,
    ) {}

    public function __invoke(Listing $listing): JsonResponse
    {
        abort_unless($listing->status === 'active', 404);
        abort_if($listing->hide_phone || $listing->contact_phone === null, 404);

        $listing->newQuery()->whereKey($listing->getKey())->increment('contacts_count');

        $this->events->record(EventName::ContactRevealed, 'listing', $listing->id, [
            'category_id' => $listing->category_id,
            'method' => 'phone',
        ]);

        return response()->json(['phone' => $listing->contact_phone]);
    }
}
