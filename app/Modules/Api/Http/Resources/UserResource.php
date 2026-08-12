<?php

namespace App\Modules\Api\Http\Resources;

use App\Models\User;
use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * الحساب كما يراه صاحبه — ولا يُستعمل لعرض طرفٍ آخر.
 *
 * ═══ لماذا هذا التحذير مكتوب ═══
 *
 * الحقل الخطر هو `phone`. رقم صاحب الحساب يخصّه، ونفس الصنف لو استُعمل
 * لتصوير البائع في بطاقة إعلان لسرّب رقم كل معلن في السوق — وهو
 * بالضبط ما يمنعه `ContactRevealController` بمسار مستقل وعدّاد. البائع
 * يُصوَّر داخل `ListingDetailResource` بحقلين لا أكثر: اسمٌ وتاريخ
 * انضمام.
 *
 * والرقم يُعاد بصيغة العرض المحلية (`0771234567`) لا بـE.164: المخزَّن
 * مطبَّع لأن القيد الفريد يحتاجه، والمعروض هو ما يعرفه صاحبه.
 *
 * ═══ can_publish محسوب لا مشتقّ في العميل ═══
 *
 * بوابة النشر شرطان — رقم موثَّق، وإعداد `require_phone_verification`.
 * والعميل الذي يشتقّها من `phone_verified_at` وحده يعرض زرّ النشر ثم
 * يُرفض بعد ملء النموذج كاملاً. القرار من حيث يُتَّخذ فعلاً.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'phone' => YemeniPhone::forDisplay($this->phone),
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl(),
            'phone_verified' => $this->hasVerifiedPhone(),
            'status' => $this->status ?? 'active',
            'can_publish' => ! (bool) config('identity.require_phone_verification_to_publish', true)
                || $this->hasVerifiedPhone(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
