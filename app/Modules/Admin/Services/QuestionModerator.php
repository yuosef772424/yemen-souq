<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Engagement\Models\ListingQuestion;
use Illuminate\Support\Facades\DB;

/**
 * إخفاء الأسئلة العامة وإظهارها.
 *
 * العمودان hidden_at وhidden_reason كُتبا في هجرة listing_questions
 * وتُركا «ينتظران لوحة الإدارة» — وهذا الملف هو ما كانا ينتظرانه.
 * وscopeVisible في الموديل يقصي المخفي أصلاً، فلا حاجة لتغيير أي شيء
 * في العرض العام.
 *
 * والإخفاء للإدارة وحدها بقصد — كما نصّ عليه تعليق الهجرة: لو استطاع
 * مالك الإعلان إخفاء «هل هي مصدومة؟» لصارت الأسئلة العامة لوحة دعاية
 * لا مصدر معلومة.
 */
final class QuestionModerator
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function hide(ListingQuestion $question, User $admin, string $reason): void
    {
        DB::transaction(function () use ($question, $admin, $reason): void {
            $question->forceFill([
                'hidden_at' => now(),
                'hidden_reason' => mb_substr($reason, 0, 160),
            ])->save();

            $this->audit->log($admin, AdminAction::QuestionHidden, $question, $reason, [
                'listing_id' => $question->listing_id,
            ]);
        });
    }

    public function unhide(ListingQuestion $question, User $admin): void
    {
        DB::transaction(function () use ($question, $admin): void {
            $question->forceFill([
                'hidden_at' => null,
                'hidden_reason' => null,
            ])->save();

            $this->audit->log($admin, AdminAction::QuestionUnhidden, $question, null, [
                'listing_id' => $question->listing_id,
            ]);
        });
    }
}
