<?php

namespace App\Modules\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * الدخول بالهاتف أو البريد — نفس حقل `identifier` في الويب.
 *
 * الحقل واحد لأن الرقم هو ما يحفظه الناس في اليمن والبريد ثانوي،
 * وإجبار المستخدم على تذكّر أيّهما سجّل به احتكاك بلا مقابل. القرار
 * يُتَّخذ في `LoginController` بمحاولة تطبيع الرقم — انظر هناك.
 *
 * و`device_name` اختياري ويُستعمل اسماً للرمز: «هاتف أحمد» في قائمة
 * الأجهزة أوضح من «mobile» عشر مرات. وهو نصٌّ يكتبه العميل فيُقصّ —
 * اسمٌ بطول ميغابايت يُكتب في عمود.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function attributes(): array
    {
        return [
            'identifier' => 'الهاتف أو البريد',
            'password' => 'كلمة المرور',
        ];
    }
}
