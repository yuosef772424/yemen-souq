<?php

namespace App\Modules\Sms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * رسائل واردة يرفعها الهاتف.
 *
 * ═══ الهاتف يرشّح قبل أن يرفع ═══
 *
 * صندوق الوارد فيه رسائل بنوك وأهل وعمل. ورفعُه كاملاً إلى خادمنا تسريبٌ
 * لا علاقة له بالتوثيق — ولا يُصلحه أن الخادم «يتجاهل ما لا يعنيه»، لأن
 * التسريب وقع بالنقل لا بالمعالجة.
 *
 * فالسكربت على الهاتف يطابق البادئة أولاً ولا يرفع غيرها. وهذا التحقق
 * حارسٌ ثانٍ: طولٌ محدود، وعدد محدود، ورقم مرسِل بصيغة معقولة.
 *
 * ═══ وحدّ الطول 64 محرفاً ═══
 *
 * الرمز مع بادئته أحد عشر محرفاً. والسقف واسعٌ لما قد يضيفه المستخدم
 * («SQ 4F7K2M شكرا») وضيّقٌ بما يمنع رفع رسالةٍ طويلة كاملة — فحتى لو
 * أخطأ مرشّح الهاتف، لا يصل نصٌّ ذو معنى.
 */
class InboundMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'messages' => ['required', 'array', 'min:1', 'max:25'],
            'messages.*.from' => ['required', 'string', 'max:20'],
            'messages.*.body' => ['required', 'string', 'max:64'],
            'messages.*.received_at' => ['nullable', 'string', 'max:40'],
        ];
    }
}
