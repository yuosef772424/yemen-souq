<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * التطبيع قبل التحقق لا بعده.
     *
     * لو تحقّقنا من المُدخل الخام ثم طبّعناه، لصار القيد الفريد على
     * الصيغة المكتوبة لا على الرقم — و0771234567 و+967771234567
     * يمرّان كحسابين مختلفين لنفس الشخص.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => YemeniPhone::normalize($this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'phone' => ['required', 'string', 'unique:users,phone'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'أدخل رقم جوال يمني صحيح — مثل 0771234567.',
            'phone.unique' => 'هذا الرقم مسجَّل بالفعل.',
            'email.unique' => 'هذا البريد مسجَّل بالفعل.',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'phone' => 'رقم الجوال',
            'email' => 'البريد الإلكتروني',
            'password' => 'كلمة المرور',
        ];
    }
}