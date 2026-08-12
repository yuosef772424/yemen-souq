<?php

namespace App\Modules\Sms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تأكيد دفعة كاملة في نداء واحد.
 *
 * ═══ لماذا دفعة لا رسالة ═══
 *
 * الهاتف يرسل خمس رسائل ثم يؤكّد. وخمسة نداءات على شبكة يمنية متقطّعة
 * يعني خمس فرص للانقطاع — وكل انقطاعٍ يترك سحبةً بلا تأكيد تنتظر
 * انتهاء مهلتها ثم تُرسَل ثانيةً. النداء الواحد يقلّص النافذة إلى واحدة.
 *
 * ═══ وclaim_token مطلوب ═══
 *
 * التأكيد بلا رمز السحبة يعني أن أي معرّف يمكن وسمه مرسَلاً. والرمز
 * يقرن التأكيد بالسحبة التي أنتجته — انظر `SmsOutbox::markSent`.
 */
class ConfirmDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'claim_token' => ['required', 'uuid'],

            /**
             * السقف على الدفعة يطابق سقف السحب مضروباً بهامش.
             *
             * الطلب يكتبه سكربت shell لا مستخدم، لكنه يصل عبر الشبكة —
             * ومصفوفةٌ بعشرة آلاف عنصر تعني عشرة آلاف استعلام تحديث في
             * طلب واحد.
             */
            'results' => ['required', 'array', 'min:1', 'max:50'],
            'results.*.id' => ['required', 'integer', 'min:1'],
            'results.*.status' => ['required', Rule::in(['sent', 'failed'])],
            'results.*.error' => ['nullable', 'string', 'max:250'],
        ];
    }
}
