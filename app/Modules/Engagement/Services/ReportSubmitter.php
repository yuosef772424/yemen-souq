<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\ListingReport;
use Illuminate\Support\Facades\DB;

/**
 * استقبال البلاغ من المستخدم.
 *
 * ═══ لماذا وُجد هذا الملف ═══
 *
 * جدول listing_reports كان موجوداً من أول هجرة، ولوحة التحكم صارت تقرأه
 * وتفصل فيه — ولا شيء في الموقع يكتب فيه صفاً واحداً. أي أن طابور
 * البلاغات كان مضموناً أن يبقى فارغاً إلى الأبد. لوحة تعمل على جدول لا
 * يكتب فيه أحد ليست ميزة مؤجَّلة بل ميزة كاذبة.
 *
 * ═══ الإبلاغ للمسجَّلين وحدهم ═══
 *
 * البديل المرفوض: السماح للزائر (reporter_id فارغ — والعمود يقبل الفراغ).
 * مرفوض لأن البلاغ سلاح: من يبلّغ على منافسه ثلاث مرات في يوم يجب أن
 * يكون معروفاً. وبلا هوية للمُبلِّغ لا معنى لإبقاء البلاغات المصروفة —
 * وهي الإشارة الوحيدة التي تكشف الإساءة (انظر ReportResolver).
 *
 * ═══ بلاغ واحد مفتوح لكل مستخدم على كل إعلان ═══
 *
 * البديل المرفوض: فهرس فريد على (listing_id, reporter_id) في المخطط.
 * يبدو أنظف، لكنه يمنع المستخدم من الإبلاغ مرة أخرى بعد أن صُرِف بلاغه
 * الأول وتغيّر الإعلان — والحالة مشروعة: أعاد المعلن نشر نفس المخالفة.
 * الشرط الصحيح شرط على المفتوح لا على كل التاريخ، وهذا لا يُعبَّر عنه
 * بفهرس فريد.
 */
final class ReportSubmitter
{
    /** أسباب مغلقة لأنها تُوجَّه للفرز لا للقراءة — تطابق enum المخطط */
    public const REASONS = ['spam', 'fraud', 'wrong_category', 'prohibited', 'duplicate', 'other'];

    public function __construct(
        private readonly EventRecorder $events,
    ) {}

    /** المالك لا يبلّغ على نفسه، وما فُتح لا يُفتح مرتين */
    public function canReport(?User $user, Listing $listing): bool
    {
        if ($user === null || $user->id === $listing->user_id) {
            return false;
        }

        return ! $this->hasOpenReport($user, $listing);
    }

    public function hasOpenReport(User $user, Listing $listing): bool
    {
        return ListingReport::query()
            ->where('listing_id', $listing->id)
            ->where('reporter_id', $user->id)
            ->open()
            ->exists();
    }

    public function submit(User $user, Listing $listing, string $reason, ?string $details = null): ?ListingReport
    {
        if (! $this->canReport($user, $listing)) {
            return null;
        }

        $report = DB::transaction(fn (): ListingReport => ListingReport::create([
            'listing_id' => $listing->id,
            'reporter_id' => $user->id,
            'reason' => in_array($reason, self::REASONS, true) ? $reason : 'other',
            'details' => $details === null ? null : mb_substr(trim($details), 0, 500),
            'status' => 'open',
            'created_at' => now(),
        ]));

        /**
         * الحدث يحمل السبب ولا يحمل النص.
         *
         * قاعدة الوحدة: لا بيانات شخصية في payload. ونص البلاغ قد يحتوي
         * اسماً أو رقماً كتبه المُبلِّغ — فيبقى في listing_reports حيث
         * يُقرأ بصلاحية، لا في سجل تحليلي يُصدَّر ويُجمَّع.
         */
        $this->events->record(EventName::ListingReported, 'listing', $listing->id, [
            'reason' => $report->reason,
        ]);

        return $report;
    }
}
