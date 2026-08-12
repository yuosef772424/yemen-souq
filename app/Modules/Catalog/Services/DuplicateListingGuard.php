<?php

namespace App\Modules\Catalog\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;

/**
 * إعلانٌ ثانٍ ببيانات الأول لا يُنشر.
 *
 * ═══ المشكلة ═══
 *
 * صفّان متطابقان في كل شيء إلا `created_at`. مصدرهما مصدران: ضغطتان على
 * زرّ النشر أو تحديث الصفحة بعد إرسالها، ونشرٌ متعمَّد لنفس السلعة
 * ليقفز الإعلان إلى رأس «الأحدث». الأول عطلٌ عندنا والثاني إساءة —
 * وأثرهما على من يتصفّح واحد: نفس السلعة مرّتين في نفس الصفحة.
 *
 * ═══ لماذا الرفض لا الدمج الصامت ═══
 *
 * قبولُ الطلب وإرجاعُ الإعلان القديم يجعل المعلن يظنّ أنه نشر شيئاً
 * جديداً. والرفض برسالة تشير إلى الإعلان القائم يقول له ما وقع فعلاً،
 * ويدلّه على التجديد — وهو ما أراده أصلاً.
 *
 * ═══ ولماذا الحدّ على الإعلانات الحيّة وحدها ═══
 *
 * `deleted_at` فارغ والحالة من الأربع الحيّة. ومن حذف إعلانه أو انتهى
 * أجله أو باع سلعته يحقّ له نشر مثله من جديد — وهذه كلها حالاتٌ يكون
 * فيها الإعلان القديم قد **غادر السوق**، فلا ازدواج يراه أحد.
 *
 * ولذلك أيضاً لا قيد فريد في القاعدة: القيد يشمل الصفوف المحذوفة
 * منطقياً والمنتهية، فيمنع النشر المشروع بلا طريقة لاستثنائه.
 */
class DuplicateListingGuard
{
    /** الحالات التي يكون فيها الإعلان ما زال في السوق أو في طريقه إليه */
    private const LIVE_STATUSES = ['draft', 'pending', 'active', 'paused'];

    public function __construct(
        private readonly ListingFingerprint $fingerprint,
    ) {}

    /**
     * الإعلان القائم الذي يطابق هذه البيانات، إن وُجد.
     *
     * `$ignoreId` للتعديل: الإعلان لا يكون نسخةً من نفسه.
     *
     * @param  array<string, mixed>  $data
     */
    public function duplicateOf(User $user, array $data, ?int $ignoreId = null): ?Listing
    {
        return Listing::query()
            ->where('user_id', $user->id)
            ->where('fingerprint', $this->fingerprint->ofInput($data))
            ->whereIn('status', self::LIVE_STATUSES)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->orderByDesc('id')
            ->first();
    }
}
