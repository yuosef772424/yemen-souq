<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Catalog\Services\ListingImageService;
use Illuminate\Http\UploadedFile;

/**
 * تعديل إعلان قائم: الحقول، ثم المواصفات، ثم الصور — بهذا الترتيب.
 *
 * ما لا يفعله مقصود بقدر ما يفعله:
 *
 *  · لا يغيّر slug عند تغيير العنوان. الرابط قد يكون منشوراً في واتساب
 *    ومحفوظاً في مفضلة زائر؛ تغييره يكسر ما لا نراه.
 *    (`ListingObserver::fillSlug` يملأ slug عند الفراغ فقط.)
 *
 *  · لا يمسّ price_in_base ولا search_blob ولا title_normalized —
 *    `ListingObserver` يعيد حسابها كلها في saving، ولو حسبناها هنا
 *    لتفرّق الحساب في موضعين.
 *
 *  · لا يسجّل تغيّر السعر: الـObserver يكتب listing_price_history في
 *    updated.
 *
 * والمواصفات تُكتب **بعد** حفظ الإعلان لا قبله: `ListingAttributes::write`
 * تقرأ category_id من النموذج لتعرف أي صفات مسموحة. فلو سبقت الحفظ لقرأت
 * التصنيف القديم — فتكتب صفات «سيارات» على إعلان صار «شققاً».
 *
 * الصلاحية (`Gate::authorize('update')`) تسبق هذا الفعل في المتحكّم، وهو
 * لا يفحصها: نقلها إلى هنا كان سيجعل فحصها يختفي من قراءة المتحكّم السريعة.
 */
final class UpdateListing
{
    public function __construct(
        private readonly ListingAttributes $attributes,
        private readonly ListingImageService $images,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  حقول الإعلان بلا `images` و`attributes`
     * @param  array<int|string, mixed>  $attributes
     * @param  array<int, UploadedFile>  $uploads
     */
    public function __invoke(Listing $listing, array $fields, array $attributes, array $uploads): ListingSaveResult
    {
        $listing->update($fields);

        $this->attributes->write($listing, $attributes);

        $result = $this->images->attach($listing, $uploads);

        return new ListingSaveResult($listing, $result['stored'], $result['failed']);
    }
}
