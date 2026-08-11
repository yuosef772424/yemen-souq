<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Engagement\Models\ListingReport;
use Illuminate\Support\Facades\DB;

/**
 * معالجة البلاغات.
 *
 * القرار المهم: البلاغ لا يُحذف عند معالجته، ولا يُعالَج بلا نتيجة
 * مكتوبة. 'actioned' و'dismissed' حالتان نهائيتان تبقيان في الجدول،
 * لأن نسبة البلاغات المصروفة على كل مُبلِّغ هي الإشارة الوحيدة التي
 * تكشف من يُبلِّغ على منافسيه. حذف البلاغ المعالَج يمحو تلك الإشارة.
 *
 * وتنفيذ البلاغ يُخفي الإعلان في نفس الترانزاكشن: بلاغ «نُفِّذ» وإعلان
 * ما زال منشوراً حالة تناقض تجعل اللوحة تكذب على من يقرأها.
 */
final class ReportResolver
{
    public function __construct(
        private readonly ListingModerator $moderator,
        private readonly AuditLogger $audit,
    ) {}

    public function dismiss(ListingReport $report, User $admin, ?string $reason = null): void
    {
        DB::transaction(function () use ($report, $admin, $reason): void {
            $report->forceFill([
                'status' => 'dismissed',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log($admin, AdminAction::ReportDismissed, $report, $reason, [
                'listing_id' => $report->listing_id,
                'report_reason' => $report->reason,
            ]);
        });
    }

    /**
     * التنفيذ: رفض الإعلان + إغلاق كل بلاغات هذا الإعلان.
     *
     * إغلاق البقية مقصود: عشرة بلاغات على إعلان واحد مشكلة واحدة لا
     * عشر. تركها مفتوحة يجعل الطابور يعرض عملاً منتهياً، فيفقد المشرف
     * ثقته في الطابور — وهذا أسرع طريق إلى لوحة تحكم مهجورة.
     */
    public function action(ListingReport $report, User $admin, string $reason): void
    {
        DB::transaction(function () use ($report, $admin, $reason): void {
            $listing = $report->listing;

            if ($listing !== null) {
                $this->moderator->reject($listing, $admin, $reason);
            }

            ListingReport::query()
                ->where('listing_id', $report->listing_id)
                ->open()
                ->update([
                    'status' => 'actioned',
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);

            $this->audit->log($admin, AdminAction::ReportActioned, $report, $reason, [
                'listing_id' => $report->listing_id,
                'report_reason' => $report->reason,
            ]);
        });
    }
}
