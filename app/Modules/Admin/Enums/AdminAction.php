<?php

namespace App\Modules\Admin\Enums;

/**
 * أنواع الإجراءات الإدارية.
 *
 * نفس حجة EventName: نص حر في عمود action يعني أن خطأ إملائياً واحداً
 * يُخرج إجراءً من كل تصفية لاحقة بلا خطأ ولا تحذير — ويصير سجل
 * المساءلة ناقصاً وأنت تظنه كاملاً.
 */
enum AdminAction: string
{
    case ListingApproved = 'listing_approved';
    case ListingRejected = 'listing_rejected';
    case ListingPaused = 'listing_paused';
    case ListingFeatured = 'listing_featured';
    case ListingUnfeatured = 'listing_unfeatured';
    case ListingDeleted = 'listing_deleted';
    case ListingRestored = 'listing_restored';
    case ReportDismissed = 'report_dismissed';
    case ReportActioned = 'report_actioned';
    case QuestionHidden = 'question_hidden';
    case QuestionUnhidden = 'question_unhidden';
    case UserSuspended = 'user_suspended';
    case UserBanned = 'user_banned';
    case UserActivated = 'user_activated';
    case UserRoleChanged = 'user_role_changed';

    /**
     * ختم مراجعة جماعي على ما كان موجوداً قبل وجود اللوحة.
     *
     * حالة منفصلة لا ListingApproved مكرّرة: «اعتمد 160 إعلاناً في نفس
     * الثانية» يقرأه من يفتّش السجل كإشراف صُوريّ. والحقيقة أنها لم
     * تُراجَع بل استُثنيت من الطابور بقرار واحد — وهذا ما يجب أن يقوله
     * السجل بالحرف.
     */
    case ListingsBaselineReviewed = 'listings_baseline_reviewed';

    public function label(): string
    {
        return match ($this) {
            self::ListingApproved => 'اعتماد إعلان',
            self::ListingRejected => 'رفض إعلان',
            self::ListingPaused => 'إيقاف إعلان',
            self::ListingFeatured => 'تمييز إعلان',
            self::ListingUnfeatured => 'إلغاء تمييز',
            self::ListingDeleted => 'حذف إعلان',
            self::ListingRestored => 'استعادة إعلان',
            self::ReportDismissed => 'صرف بلاغ',
            self::ReportActioned => 'تنفيذ بلاغ',
            self::QuestionHidden => 'إخفاء سؤال',
            self::QuestionUnhidden => 'إظهار سؤال',
            self::UserSuspended => 'إيقاف حساب',
            self::UserBanned => 'حظر حساب',
            self::UserActivated => 'تنشيط حساب',
            self::UserRoleChanged => 'تغيير دور',
            self::ListingsBaselineReviewed => 'خطّ أساس للمراجعة',
        };
    }

    /** الإجراءات التي يلزمها سبب مكتوب — كل ما يُضيّق على مستخدم */
    public function requiresReason(): bool
    {
        return in_array($this, [
            self::ListingRejected,
            self::ReportActioned,
            self::QuestionHidden,
            self::UserSuspended,
            self::UserBanned,
        ], true);
    }
}
