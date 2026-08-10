<?php

namespace App\Modules\Engagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * الرسالة الخاصة بلا حارس أرقام.
 *
 * بين طرفين اثنين لا معنى للمنع: تبادل الرقم هو الغرض من المحادثة. الحارس
 * يخصّ ما يُعرض للعموم وحده — وخلط الحالتين يجعل الميزة تعاقب استخدامها
 * الصحيح.
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'اكتب رسالتك.',
            'body.max' => 'الرسالة طويلة — اختصرها.',
        ];
    }
}