<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\ListingQuestion;

/**
 * الأسئلة العامة.
 *
 * الخدمة رقيقة بقصد: الفحوص الحقيقية موزّعة على مواضعها —
 * الصلاحية في ListingQuestionPolicy، ومنع الأرقام في AskQuestionRequest
 * عبر PublicContactGuard، والحدّ الزمني في المتحكّم. جمعها كلها هنا كان
 * سيجعل الخدمة هي التطبيق.
 */
final class QuestionService
{
    public function canAsk(User $user, Listing $listing): bool
    {
        // المالك لا يسأل نفسه سؤالاً عاماً — لو أراد إضافة معلومة فمكانها
        // الوصف، وهو يملك تعديله
        if ($listing->user_id === $user->id) {
            return false;
        }

        return ! $listing->trashed() && $listing->status === 'active' && ! $listing->isExpired();
    }

    public function ask(User $asker, Listing $listing, string $body): ListingQuestion
    {
        return ListingQuestion::create([
            'listing_id' => $listing->id,
            'asker_id' => $asker->id,
            'body' => $body,
        ]);
    }

    /**
     * الجواب يُكتب مرة ويُعدَّل.
     *
     * answered_at لا يُحرَّك عند التعديل: تاريخ الجواب هو متى أجاب البائع
     * أولاً، وهو ما يقيس سرعة استجابته. تحريكه مع كل تصحيح إملائي يجعل
     * كل بائع يبدو مستجيباً فوراً.
     */
    public function answer(ListingQuestion $question, string $answer): ListingQuestion
    {
        $question->forceFill([
            'answer' => $answer,
            'answered_at' => $question->answered_at ?? now(),
        ])->save();

        return $question;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ListingQuestion> */
    public function forListing(Listing $listing)
    {
        return ListingQuestion::query()
            ->visible()
            ->where('listing_id', $listing->id)
            ->with('asker')
            // المُجاب أولاً: الجواب هو ما جاء الزائر ليقرأه، والسؤال بلا
            // جواب معلومة عن البائع لا عن السلعة
            ->orderByRaw('answered_at is null')
            ->latest('created_at')
            ->get();
    }
}