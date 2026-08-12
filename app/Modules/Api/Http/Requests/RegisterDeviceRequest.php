<?php

namespace App\Modules\Api\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تسجيل الجهاز ورمز الدفع.
 *
 * ═══ device_uuid يصنعه العميل ولا يُشتقّ من الرمز ═══
 *
 * القاعدة موثَّقة في `UserDevice`: التعريف برمز الدفع ينكسر لأن الرمز
 * يُدوَّر دورياً، فيتراكم للمستخدم الواحد عشرون صفاً لجهاز واحد ويصله
 * الإشعار عشرين مرة. المعرّف ثابتٌ لتنصيب التطبيق، ويُرسَل في كل تسجيل.
 *
 * ═══ push_token يقبل الفراغ ═══
 *
 * `nullable` لا `required` بقصد: التطبيق يسجّل جهازه عند الدخول قبل أن
 * يُمنح إذن الإشعارات، ثم يسجّله ثانيةً بالرمز حين يُمنح. واشتراط الرمز
 * يعني أن الجهاز لا يُسجَّل أصلاً حتى يوافق صاحبه — فيضيع `last_seen_at`
 * وسجلّ الجلسة.
 *
 * والفراغ لا يمسح رمزاً قائماً — انظر `DeviceRegistrar::register`.
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_uuid' => ['required', 'string', 'min:8', 'max:64'],
            'platform' => ['required', Rule::in(['web', 'android', 'ios'])],
            'push_token' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function attributes(): array
    {
        return [
            'device_uuid' => 'معرّف الجهاز',
            'platform' => 'المنصة',
            'push_token' => 'رمز الدفع',
        ];
    }
}
