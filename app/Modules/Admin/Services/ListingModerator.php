<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * انتقالات حالة الإعلان بيد الإدارة.
 *
 * ═══ لماذا خدمة لا كود في المتحكّم ═══
 *
 * كل إجراء هنا شيئان لا واحد: تغيير في listings وصفّ في admin_actions.
 * تركهما في المتحكّم يعني أن أحدهما قد ينجح والآخر يفشل — إعلان مرفوض
 * بلا سجل يقول من رفضه ولماذا، وهو أسوأ الحالتين. الترانزاكشن هنا
 * يجعلهما فعلاً واحداً غير قابل للانقسام.
 *
 * ═══ الرفض لا يحذف، والحذف لا يُفرَّغ ═══
 *
 * الرفض يضع status = 'rejected' ويحفظ السبب في reject_reason الموجود
 * أصلاً في المخطط. الإعلان يبقى في القاعدة ويبقى مرئياً لصاحبه في
 * «إعلاناتي» بحالة «مرفوض» — لأن معلناً يجد إعلانه اختفى بلا كلمة
 * يعيد نشره فوراً، فيكرّر العمل على الطرفين. والسبب المكتوب هو الفرق
 * بين إشراف وحجب.
 *
 * ═══ ما لا تفعله هذه الخدمة ═══
 *
 * لا ترسل إشعاراً للمعلن. جداول الإشعارات موجودة ووحدة Notifications
 * فارغة من المنطق — فالمعلن لا يعرف بالرفض إلا إذا دخل لوحته. هذه
 * ثغرة معروفة لا سهو، وموضعها الصحيح وحدة الإشعارات لا هنا.
 */
final class ListingModerator
{
    /** مدة التمييز الافتراضية بالأيام */
    public const FEATURE_DAYS = 7;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * اعتماد: «رآه بشر وأقرّه».
     *
     * لا يغيّر status إن كان الإعلان منشوراً أصلاً — الاعتماد هنا ختم
     * مراجعة لا فعل نشر. أما الإعلان المرفوض سابقاً فاعتماده يعيده إلى
     * active ويمحو سبب الرفض، وإلا بقي نصّ الرفض معروضاً لصاحب إعلان
     * مقبول.
     */
    public function approve(Listing $listing, User $admin): void
    {
        DB::transaction(function () use ($listing, $admin): void {
            $before = $listing->status;

            $listing->forceFill([
                'status' => $listing->status === 'rejected' ? 'active' : $listing->status,
                'reject_reason' => null,
                'published_at' => $listing->published_at ?? now(),
                'expires_at' => $listing->expires_at ?? now()->addDays(30),
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
            ])->save();

            $this->audit->log($admin, AdminAction::ListingApproved, $listing, null, [
                'status_before' => $before,
                'status_after' => $listing->status,
            ]);
        });
    }

    public function reject(Listing $listing, User $admin, string $reason): void
    {
        DB::transaction(function () use ($listing, $admin, $reason): void {
            $before = $listing->status;

            $listing->forceFill([
                'status' => 'rejected',
                'reject_reason' => $reason,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
            ])->save();

            $this->audit->log($admin, AdminAction::ListingRejected, $listing, $reason, [
                'status_before' => $before,
            ]);
        });
    }

    /**
     * الإيقاف: يُخرج الإعلان من النتائج ويبقيه ملكاً لصاحبه.
     *
     * الفرق عن الرفض ليس شكلياً: 'paused' حالة يستطيع المعلن الخروج
     * منها بالتجديد (RENEWABLE_STATUSES تضمّها)، و'rejected' لا. فالإيقاف
     * لأمر يصلحه المعلن، والرفض لما لا يُصلح.
     */
    public function pause(Listing $listing, User $admin, ?string $reason = null): void
    {
        DB::transaction(function () use ($listing, $admin, $reason): void {
            $before = $listing->status;

            $listing->forceFill([
                'status' => 'paused',
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
            ])->save();

            $this->audit->log($admin, AdminAction::ListingPaused, $listing, $reason, [
                'status_before' => $before,
            ]);
        });
    }

    /**
     * التمييز بمدة، لا تمييزاً أبدياً.
     *
     * is_featured وحده يعني إعلاناً مثبّتاً في أعلى النتائج إلى الأبد،
     * وscopeFeaturedFirst يفحص featured_until أصلاً — فتمييز بلا تاريخ
     * انتهاء يخالف ما بُني عليه الترتيب. والمدة تجعل الترقية قابلة
     * للبيع لاحقاً بلا تغيير في المخطط.
     */
    public function feature(Listing $listing, User $admin, int $days = self::FEATURE_DAYS): void
    {
        $days = max(1, min(90, $days));

        DB::transaction(function () use ($listing, $admin, $days): void {
            $listing->forceFill([
                'is_featured' => true,
                'featured_until' => now()->addDays($days),
            ])->save();

            $this->audit->log($admin, AdminAction::ListingFeatured, $listing, null, ['days' => $days]);
        });
    }

    public function unfeature(Listing $listing, User $admin): void
    {
        DB::transaction(function () use ($listing, $admin): void {
            $listing->forceFill([
                'is_featured' => false,
                'featured_until' => null,
            ])->save();

            $this->audit->log($admin, AdminAction::ListingUnfeatured, $listing);
        });
    }

    /**
     * حذف منطقي — بنفس حجة AccountListingController::destroy.
     *
     * القيود التسلسلية على listing_images وlisting_daily_stats
     * وlisting_price_history تعني أن الحذف النهائي يمحو سجل المشاهدات
     * وتاريخ السعر معه. زرّ في لوحة تحكم يُتلف بيانات المشروع بلا رجعة
     * ليس زرّاً، بل فخّ.
     */
    public function delete(Listing $listing, User $admin, ?string $reason = null): void
    {
        DB::transaction(function () use ($listing, $admin, $reason): void {
            $this->audit->log($admin, AdminAction::ListingDeleted, $listing, $reason, [
                'status_before' => $listing->status,
            ]);

            $listing->delete();
        });
    }

    public function restore(Listing $listing, User $admin): void
    {
        DB::transaction(function () use ($listing, $admin): void {
            $listing->restore();

            $this->audit->log($admin, AdminAction::ListingRestored, $listing);
        });
    }
}
