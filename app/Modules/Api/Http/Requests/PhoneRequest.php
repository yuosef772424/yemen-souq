<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * رقم جوال وحده — لطلب رمز الاستعادة.
 *
 * التحقق يرفض غير اليمني هنا لا في المتحكّم: `YemeniPhone::normalize`
 * تُرجع null لما لا تفهمه، وnull يمرّ من `required` لو طُبِّع بعد التحقق.
 * الترتيب — تطبيعٌ ثم `required` — هو ما يجعل القاعدة تعمل.
 */
class PhoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'أدخل رقم جوال يمني صحيح — مثل 0771234567.',
        ];
    }

    public function attributes(): array
    {
        return ['phone' => 'رقم الجوال'];
    }
}
