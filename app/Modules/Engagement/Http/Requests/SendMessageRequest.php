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
            // حروف وأرقام وشرطات: يسع UUID وما شابهه، ولا يسع أي شيء يُحقن
            'client_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }

    public function clientId(): ?string
    {
        $value = $this->validated('client_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function messages(): array
    {
        return [
            'body.required' => 'اكتب رسالتك.',
            'body.max' => 'الرسالة طويلة — اختصرها.',
            'client_id.max' => 'معرّف الرسالة طويل.',
            'client_id.regex' => 'معرّف الرسالة غير صالح.',
        ];
    }
}
