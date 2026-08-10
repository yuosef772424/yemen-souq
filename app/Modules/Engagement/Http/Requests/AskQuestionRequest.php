<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Shared\Services\PublicContactGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AskQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:8', 'max:400', $this->noPhoneNumber()],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'اكتب سؤالك.',
            'body.min' => 'السؤال قصير جداً — اجعله واضحاً حتى يستطيع البائع الإجابة.',
            'body.max' => 'السؤال طويل — اختصره أو أرسل رسالة خاصة.',
        ];
    }

    /**
     * قاعدة مضمّنة لا صنف Rule منفصل.
     *
     * القاعدة تُستخدم في موضعين فقط (السؤال والجواب) وكلاهما في هذا
     * المجلد. صنف منفصل في app/Rules يفرّق التحقق عن سياقه، ويجعل قارئ
     * الطلب لا يرى ما يُمنع — وهو أهم ما يجب أن يراه هنا.
     */
    private function noPhoneNumber(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                if (app(PublicContactGuard::class)->containsPhoneNumber(is_string($value) ? $value : null)) {
                    $fail('لا تكتب رقم هاتف في نص يظهر للعموم — استخدم الرسائل الخاصة، أو زرّ إظهار الرقم في الإعلان.');
                }
            }
        };
    }
}