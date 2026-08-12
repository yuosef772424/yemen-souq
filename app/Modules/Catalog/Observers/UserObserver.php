<?php

namespace App\Modules\Catalog\Observers;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;

/**
 * حسابٌ يُغلق تُغادر إعلاناته السوق معه.
 *
 * ═══ لماذا هذا ضروري ═══
 *
 * `listings.user_id` مفتاح أجنبي بـ`cascadeOnDelete` — وهو يعمل عند
 * الحذف الحقيقي وحده. وإغلاق الحساب حذفٌ **منطقي**، فالقيد لا يُنادى،
 * وتبقى الإعلانات معروضةً في السوق برقم تواصلٍ لا يردّ عليه أحد.
 *
 * والأسوأ أن `Listing::scopePublished` لا تفحص صاحب الإعلان، فلا شيء
 * يُخفيها. عطلٌ صامت من نفس عائلة `price_in_base` الفارغ.
 *
 * ═══ ولماذا هنا لا في وحدة الهوية ═══
 *
 * اتجاه الاعتماد: `Catalog` تعرف `Identity` ولا عكس. فمتحكّم إغلاق
 * الحساب في `Identity` لا يستورد `Listing`، ووحدة `Catalog` هي التي
 * تستمع إلى الحدث وتتصرّف فيما تملك.
 *
 * وهذا يعني أيضاً أن حذف وحدة `Catalog` كاملةً لا يترك في `Identity`
 * سطراً معطَّلاً.
 */
class UserObserver
{
    /**
     * `deleting` لا `deleted`: الحذف المنطقي يجري في معاملة المتحكّم،
     * والإخفاء يجب أن يقع فيها — لا بعد نجاحٍ قد لا يقع.
     */
    public function deleting(User $user): void
    {
        // الاستعلام لا الحلقة: مالكٌ بمئة إعلان لا يستحق مئة استعلام،
        // وSoftDeletes على الاستعلام يكتب deleted_at بلا إيقاظ الـObserver
        Listing::query()
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * واستعادة الحساب تعيد إعلاناته.
     *
     * غيابها يعني أن حساباً أُغلق بالخطأ ثم استُعيد يرجع فارغاً — وصاحبه
     * لا يملك طريقاً لإعادة ما لا يراه.
     */
    public function restored(User $user): void
    {
        Listing::onlyTrashed()
            ->where('user_id', $user->id)
            ->restore();
    }
}
