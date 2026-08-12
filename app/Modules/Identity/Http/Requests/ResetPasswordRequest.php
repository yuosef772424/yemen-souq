<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * الرمز وكلمة المرور الجديدة.
 *
 * الرقم ليس حقلاً هنا: يأتي من الجلسة لا من الطلب — انظر
 * PasswordResetController. حقلٌ للرقم في هذا النموذج يعني أن الرمز
 * وكلمة المرور والهدف كلها من المتصفح، فيصير المسار قابلاً للتوجيه
 * إلى حسابٍ آخر لو ضعُف الربط بينها يوماً.
 *
 * وقواعد كلمة المرور نفس RegisterRequest حرفياً. لو تراخت هنا لصار
 * مسار الاستعادة بابَ التفافٍ على سياسة التسجيل: من يريد كلمة ضعيفة
 * يسجّل بقوية ثم «ينساها».
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'أدخل الرمز المرسَل إلى جوالك.',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين.',
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'الرمز',
            'password' => 'كلمة المرور',
        ];
    }
}
