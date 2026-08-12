<?php

namespace App\Modules\Api\Support;

use Illuminate\Http\Request;

/**
 * حجم الصفحة كما يطلبه العميل، محصوراً بالسقف.
 *
 * موضعه هنا لا في كل متحكّم: ستّ نقاط نهاية تُرقّم، وقراءة `per_page`
 * في كلٍّ منها تعني ستّ نسخ من نفس الحدّ — وأول واحدة تُنسى تفتح طلباً
 * بعشرة آلاف صف.
 *
 * والقراءة تتحمّل المدخل الغريب: `?per_page[]=1` مصفوفة، و`(int)array`
 * تحذيرٌ يحوّله لارافل إلى استثناء. نفس درس `SearchCriteria`.
 */
final class Pagination
{
    public static function perPage(Request $request): int
    {
        $default = (int) config('api.pagination.default', 20);
        $max = (int) config('api.pagination.max', 50);

        $value = $request->input('per_page');

        if (! is_scalar($value) || ! is_numeric($value)) {
            return $default;
        }

        return max(1, min((int) $value, $max));
    }
}
