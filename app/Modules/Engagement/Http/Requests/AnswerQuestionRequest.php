<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Shared\Services\PublicContactGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AnswerQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'answer' => ['required', 'string', 'min:2', 'max:800', $this->noPhoneNumber()],
        ];
    }

    public function messages(): array
    {
        return [
            'answer.required' => 'اكتب الجواب.',
            'answer.max' => 'الجواب طويل — اختصره.',
        ];
    }

    /**
     * المنع يشمل جواب المالك أيضاً.
     *
     * وهذه الحالة أهم من السؤال: البائع هو من له مصلحة في نشر رقمه علناً
     * لتجاوز زرّ إظهار الرقم. استثناؤه يُفرغ القاعدة من معناها.
     */
    private function noPhoneNumber(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                if (app(PublicContactGuard::class)->containsPhoneNumber(is_string($value) ? $value : null)) {
                    $fail('لا تكتب رقم هاتف في جواب يظهر للعموم — رقمك يظهر عبر زرّ إظهار الرقم في إعلانك.');
                }
            }
        };
    }
}