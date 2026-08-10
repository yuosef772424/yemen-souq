<?php

namespace App\Modules\Search\Services;

use App\Modules\Shared\Services\LatinArabicTransliterator;

/**
 * توسيع كلمات الاستعلام من مصدرين مختلفي الطبيعة.
 *
 * الفصل مقصود ودائم:
 *
 *   SynonymExpander            بيانات — جدول يُراجَع ويُدقَّق ويُرقّى ببوابة
 *   LatinArabicTransliterator  قاعدة  — ثلاثون سطراً، تحقُّقها اختبار وحدة
 *
 * دمجهما في صنف واحد يخلط ما يحتاج مراجعة بشرية بما لا يحتاجها، فيصبح
 * كل تغيير في القاعدة الحتمية مشروطاً بمراجعة لا معنى لها — والعكس:
 * تصبح صفوف الجدول كأنها قواعد لا تُساءل.
 *
 * وهذا الصنف لا يعرف من أين تأتي أي منهما: مهمته وحيدة، أن يبني
 * المجموعة النهائية لكل كلمة ويحدّ حجمها.
 */
final class QueryExpander
{
    /**
     * سقف حجم المجموعة الواحدة.
     *
     * ثلاث مكافئات + صورتان منقولتان + الكلمة نفسها. وسقف الكلمات ثمانٍ
     * في SearchTokenizer، فالحدّ الأعلى ثمانٍ × ست = 48 شرط LIKE — كثير،
     * لكنه سقف نظري لا حالة واقعية: أغلب الكلمات بلا مكافئ ولا صورة.
     */
    public const MAX_PER_GROUP = 6;

    public function __construct(
        private readonly SynonymExpander $synonyms,
        private readonly LatinArabicTransliterator $transliterator,
    ) {
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, array<int, string>>
     */
    public function expand(array $tokens): array
    {
        $groups = [];

        foreach ($this->synonyms->expand($tokens) as $group) {
            $variants = [];

            // النقل يُطبَّق على المكافئات أيضاً: من كتب «موتر اس22» يستحق
            // أن تعمل الكلمتان معاً، لا واحدة منهما
            foreach ($group as $word) {
                $variants = [...$variants, ...$this->transliterator->variants($word)];
            }

            $groups[] = array_slice(
                array_values(array_unique([...$group, ...$variants])),
                0,
                self::MAX_PER_GROUP
            );
        }

        return $groups;
    }
}