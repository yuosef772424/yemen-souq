<?php

namespace App\Modules\Engagement\Policies;

use App\Models\User;
use App\Modules\Engagement\Models\ListingQuestion;

/**
 * من يفعل ماذا بالسؤال العام.
 *
 * التقسيم هو القرار: المالك يجيب ولا يحذف، والسائل يحذف ولا يجيب.
 *
 * ولا صلاحية حذف للمالك بقصد — انظر تعليق الهجرة: لو حذف المالك السؤال
 * المحرج لصارت الأسئلة العامة لوحة دعاية، وفقد المشتري السبب الوحيد
 * لقراءتها. الإخفاء للإدارة، وهو ليس صلاحية مستخدم أصلاً.
 */
class ListingQuestionPolicy
{
    public function answer(User $user, ListingQuestion $question): bool
    {
        return $user->id === $question->listing?->user_id;
    }

    public function delete(User $user, ListingQuestion $question): bool
    {
        return $user->id === $question->asker_id;
    }
}