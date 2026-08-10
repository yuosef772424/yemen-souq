<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ArabicNormalizer;

/**
 * يبني النص المجمّع لإعلان واحد.
 *
 * لماذا في Catalog لا في Search: العمود على جدول listings، وCatalog يملكه.
 * وحدة Search تستهلك العمود ولا تكتبه — وإلا انقلب اتجاه الاعتماد.
 *
 * صنف منفصل لا دالة خاصة داخل الـObserver، لأن أمر إعادة البناء
 * يحتاج المنطق نفسه. منطق مكرَّر في موضعين يتباعد حتماً.
 */
final class ListingBlobBuilder
{
    public function __construct(
        private readonly ArabicNormalizer $normalizer,
    ) {
    }

    public function for(Listing $listing): string
    {
        $parts = [
            $listing->title,
            $listing->description,
        ];

        // find() لا العلاقة: الـObserver يعمل أثناء الحفظ وقد لا تكون محمّلة
        $category = $listing->category_id !== null
            ? Category::find($listing->category_id)
            : null;

        if ($category !== null) {
            $parts[] = $category->name_ar;
        }

        $location = $listing->location_id !== null
            ? Location::find($listing->location_id)
            : null;

        if ($location !== null) {
            $parts[] = $location->name_ar;

            // المحافظة أيضاً: من يكتب «صنعاء» يقصد كل مديرياتها
            if ($location->parent_id !== null) {
                $parts[] = Location::find($location->parent_id)?->name_ar;
            }
        }

        $joined = implode(' ', array_filter($parts, fn (?string $p): bool => $p !== null && $p !== ''));

        return $this->normalizer->normalize($joined);
    }
}