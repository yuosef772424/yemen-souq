<?php

namespace App\Modules\Search\Pagination;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ترقيمٌ لا يَعِد برقمٍ لم يُعدّ.
 *
 * ═══ لماذا سقفٌ أصلاً ═══
 *
 * `COUNT(*)` على نتيجة بحث يعني زيارة كل صفٍّ مطابق. وهذا رخيصٌ للكلمة
 * النادرة وكارثيٌّ للشائعة: كلمة تطابق 12% من مليون صف تُكلّف ثوانيَ في
 * كل بحث — لتُعرض في النهاية أربعٌ وعشرون نتيجة لا يقرأ أحدٌ ما بعدها.
 *
 * والسقف يجعل التكلفة ثابتة: نعدّ إلى `RESULT_CAP + 1` ثم نتوقف. الصفّ
 * الزائد هو كل الفرق بين «ألف نتيجة» و«أكثر من ألف» — به وحده نعرف أننا
 * بلغنا السقف بلا أن نعدّ ما بعده.
 *
 * وهو عُرفٌ مستقر لا اختراع: GitHub يسقّف البحث عند ألف نتيجة، وGoogle
 * يعرض تقديراً لا عدداً.
 *
 * ═══ وما يترتّب عليه ═══
 *
 * `total()` تُرجع العدد المسقوف — أي أن آخر صفحة قابلة للوصول محدودة
 * كذلك. وهذا مقصود: الترقيم العميق يدفع ثمن `OFFSET` كبير ولا يقرؤه أحد.
 *
 * و`isCapped()` هي ما يميّز «ألفٌ بالضبط» من «ألفٌ فأكثر»، فالواجهة
 * تعرض علامة الزيادة بدل أن تكذب برقمٍ دقيق.
 */
final class BoundedPaginator extends LengthAwarePaginator
{
    /**
     * @param  bool  $capped  هل بلغ العدّ السقف فتوقّف قبل نهاية النتائج؟
     */
    public function __construct(
        mixed $items,
        int $total,
        int $perPage,
        int $currentPage,
        array $options,
        private readonly bool $capped,
    ) {
        parent::__construct($items, $total, $perPage, $currentPage, $options);
    }

    public function isCapped(): bool
    {
        return $this->capped;
    }
}
