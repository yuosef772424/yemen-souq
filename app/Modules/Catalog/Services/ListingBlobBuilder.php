<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ArabicAffixStripper;
use App\Modules\Shared\Services\ArabicNormalizer;
use App\Modules\Shared\Services\LatinArabicTransliterator;

/**
 * يبني النص المجمّع لإعلان واحد.
 *
 * لماذا في Catalog لا في Search: العمود على جدول listings، وCatalog يملكه.
 * وحدة Search تستهلك العمود ولا تكتبه — وإلا انقلب اتجاه الاعتماد.
 *
 * صنف منفصل لا دالة خاصة داخل الـObserver، لأن أمر إعادة البناء يحتاج
 * المنطق نفسه. منطق مكرَّر في موضعين يتباعد حتماً.
 *
 * ═══ الإضافة: صور أكواد الموديلات ═══
 *
 * الفهرسة هي المكان الصحيح للنقل الحرفي، لا الاستعلام. عنوان «سامسونج
 * اس22» يُخزَّن ومعه s22، و«Galaxy S 22» يُخزَّن ومعه s22 كذلك —
 * فيلتقي الاثنان في صورة واحدة بلا أي شرط LIKE إضافي وقت البحث. أما
 * التوسيع وقت الاستعلام فيضاعف الشروط على مسح كامل للجدول في كل بحث.
 *
 * الكلفة: عمود أطول قليلاً، وإعادة بناء لازمة مرة واحدة
 * (catalog:rebuild-search-blob) — وإلا فالإعلانات القديمة لا تحمل
 * الصور الجديدة فتبدو الميزة عاملة للجديد وحده.
 */
final class ListingBlobBuilder
{
    public function __construct(
        private readonly ArabicNormalizer $normalizer,
        private readonly LatinArabicTransliterator $transliterator,
        private readonly ArabicAffixStripper $stripper,
    ) {}

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

        $blob = $this->normalizer->normalize($joined);

        /**
         * الصور تُشتق من العنوان وحده لا من الوصف.
         *
         * الوصف نص حر طويل مليء بأرقام (سعر، سنة، مسافة، رقم هاتف)،
         * فاشتقاق الصور منه يولّد كلمات لا يقصدها أحد ويُلوّث العمود.
         * كود الموديل يُكتب في العنوان — هناك يضعه من يريد أن يُعثر عليه.
         */
        $variants = $this->transliterator->variants($listing->title);

        $blob = $variants === [] ? $blob : $blob.' '.implode(' ', $variants);

        return $this->withBareForms($blob);
    }

    /**
     * الصيغ المجرَّدة تُضاف إلى النص لا تحلّ محلّه.
     *
     * السبب أن المطابقة صارت على فهرس FULLTEXT، وهو يطابق كلمات كاملة:
     * البحث عن «سياره» لا يجد «بالسياره» ما لم تكن المجرَّدة مفهرسة
     * معها. والأصلية تبقى لأن من يكتب «بالسياره» يجب أن يجدها أيضاً.
     *
     * والكلفة عمود أطول — ولا شيء غير ذلك: الإضافة تجري وقت الحفظ مرة
     * واحدة، لا وقت الاستعلام في كل بحث.
     */
    private function withBareForms(string $blob): string
    {
        $words = array_filter(explode(' ', $blob), fn (string $w): bool => $w !== '');

        $extra = [];

        foreach (array_unique($words) as $word) {
            $bare = $this->stripper->bare($word);

            if ($bare !== null && ! in_array($bare, $words, true)) {
                $extra[$bare] = true;
            }
        }

        return $extra === [] ? $blob : $blob.' '.implode(' ', array_keys($extra));
    }
}
