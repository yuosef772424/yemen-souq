<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * تغيير كلمة المرور من داخل الحساب.
 *
 * `current_password` ليس تجميلاً: جهازٌ تُرك مفتوحاً في مقهى يصير — بلا
 * هذا الشرط — استيلاءً كاملاً على الحساب بضغطتين، ولا يبقى لصاحبه طريق
 * إليه. والشرط يجعل الجلسة المسروقة قادرةً على القراءة لا على الإقفال.
 *
 * وهو نفس السبب الذي يجعل مسار الاستعادة تحت `guest`: من هو داخل
 * الحساب أصلاً لا يحتاجه.
 */
class UpdatePasswordRequest extends FormRequest
{
    protected $errorBag = 'password';

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'أدخل كلمة المرور الحالية.',
            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين.',
        ];
    }

    public function attributes(): array
    {
        return [
            'current_password' => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ];
    }
}
