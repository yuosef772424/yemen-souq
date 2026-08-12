<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * الاستعادة في طلب واحد — الرقم والرمز وكلمة المرور معاً.
 *
 * ═══ لماذا يختلف عن ResetPasswordRequest في الويب ═══
 *
 * هناك الرقم يأتي من **الجلسة**، وتعليق `PasswordResetController` يشرح
 * السبب: المسار خطوتان متتاليتان في نفس المتصفح، ووضع الرقم في حقل
 * مخفي يتركه معروضاً على شاشة تُترك مفتوحة.
 *
 * والـAPI بلا حالة — لا جلسة تحمل شيئاً بين طلبين. فالرقم يُرسَل في
 * الجسد، وهذا **لا يُضعف المسار**: الرمز مربوط بالرقم في قاعدة البيانات
 * ومجزَّأ فيها، فمن يرسل رقماً غير الذي أُرسل إليه الرمز يفشل التحقق.
 * ما يحمي الحساب هو وصول الرمز إلى جهاز صاحبه، لا خفاء الرقم.
 *
 * ═══ وقواعد كلمة المرور نفس التسجيل حرفياً ═══
 *
 * نفس حجّة الويب: لو تراخت هنا لصار مسار الاستعادة بابَ التفافٍ على
 * سياسة التسجيل — من يريد كلمة ضعيفة يسجّل بقوية ثم «ينساها».
 */
class ResetPasswordByPhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** التطبيع قبل التحقق — بنفس حجّة RegisterRequest */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => YemeniPhone::normalize($this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'أدخل رقم جوال يمني صحيح — مثل 0771234567.',
            'code.required' => 'أدخل الرمز المرسَل إلى جوالك.',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين.',
        ];
    }

    public function attributes(): array
    {
        return [
            'phone' => 'رقم الجوال',
            'code' => 'الرمز',
            'password' => 'كلمة المرور',
        ];
    }
}
