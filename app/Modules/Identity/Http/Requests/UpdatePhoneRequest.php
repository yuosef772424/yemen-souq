<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تغيير رقم الجوال — وهو تغيير هوية لا تغيير بيان.
 *
 * الرقم في هذا السوق هو ما يُعرَف به الحساب: به يدخل صاحبه، وإليه يصل
 * رمز الاستعادة. ولذلك شرطان لا واحد:
 *
 * ١. **كلمة المرور** — نفس منطق تغييرها: جهازٌ تُرك مفتوحاً لا يجوز أن
 *    ينقل الحساب إلى رقم غريب.
 * ٢. **التطبيع قبل التحقق** — وإلا صار القيد الفريد على الصيغة المكتوبة
 *    لا على الرقم، فيمرّ `0771234567` بينما `+967771234567` مسجَّل.
 *
 * والتوثيق يسقط بعد الحفظ — في المتحكّم، لأنه أثرٌ لا قاعدة تحقق.
 */
class UpdatePhoneRequest extends FormRequest
{
    protected $errorBag = 'phone';

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => YemeniPhone::normalize($this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => [
                'required', 'string',
                Rule::unique('users', 'phone')->ignore($this->user()->id),
            ],
            'current_password' => ['required', 'current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'أدخل رقم جوال يمني صحيح — مثل 0771234567.',
            'phone.unique' => 'هذا الرقم مسجَّل في حساب آخر.',
            'current_password.required' => 'أدخل كلمة المرور لتأكيد تغيير الرقم.',
            'current_password.current_password' => 'كلمة المرور غير صحيحة.',
        ];
    }

    public function attributes(): array
    {
        return [
            'phone' => 'رقم الجوال',
            'current_password' => 'كلمة المرور',
        ];
    }
}
