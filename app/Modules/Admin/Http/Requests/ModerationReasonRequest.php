<?php

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * السبب المكتوب.
 *
 * طلب واحد يخدم الرفض وتنفيذ البلاغ وإخفاء السؤال وحظر الحساب، لأن
 * القاعدة واحدة في الأربعة: كل إجراء يُضيّق على مستخدم يحتاج سبباً
 * يُقرأ بعد شهر.
 *
 * والحد الأدنى عشرة أحرف بقصد: «مخالف» و«spam» تمرّ من required وتصنع
 * سجلاً بحجم كامل بلا معلومة فيه. والصلاحية هنا لا تُفحَص — الوسيطان
 * EnsureIsStaff وEnsureIsAdmin أقفلا المسار قبل الوصول إلى الطلب،
 * وتكرار الفحص في موضعين يعني موضعين قد يتناقضان.
 */
class ModerationReasonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'السبب'];
    }

    public function messages(): array
    {
        return [
            'reason.min' => 'اكتب سبباً مفهوماً — سيُقرأ بعد شهور، ولصاحب الإعلان حق معرفته.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
