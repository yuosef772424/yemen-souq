<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\ListingQuestion;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\Notifier;
use Illuminate\Support\Str;

/**
 * الأسئلة العامة.
 *
 * الخدمة رقيقة بقصد: الفحوص الحقيقية موزّعة على مواضعها —
 * الصلاحية في ListingQuestionPolicy، ومنع الأرقام في AskQuestionRequest
 * عبر PublicContactGuard، والحدّ الزمني في المتحكّم. جمعها كلها هنا كان
 * سيجعل الخدمة هي التطبيق.
 *
 * ═══ الإشعار هنا يحمل النصّ، وفي المحادثات لا ═══
 *
 * الفرق ليس تناقضاً بل هو الفرق نفسه بين الميزتين: السؤال والجواب
 * معروضان على صفحة الإعلان لكل زائر، فنسخُ نصّهما في الإشعار لا يكشف
 * شيئاً مكتوماً. أما الرسالة الخاصة فمكتومة بتعريفها — انظر
 * ConversationService::notify.
 */
final class QuestionService
{
    /** ما يُقتطف من النصّ في جسد الإشعار */
    private const EXCERPT = 120;

    public function __construct(
        private readonly Notifier $notifier,
    ) {}

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
        $question = ListingQuestion::create([
            'listing_id' => $listing->id,
            'asker_id' => $asker->id,
            'body' => $body,
        ]);

        // الوجهة صندوق الأسئلة لا صفحة الإعلان: البائع جاء ليجيب، وصفحة
        // الإعلان تعرض السؤال بلا حقل جواب — فيقرأ الإشعار ثم لا يجد ما
        // يفعله
        $this->notifier->send(
            recipient: $listing->user,
            type: NotificationType::NewQuestion,
            title: 'سؤال جديد على: '.$listing->title,
            body: Str::limit($body, self::EXCERPT),
            data: [
                'entity_type' => 'listing_question',
                'entity_id' => $question->id,
                'listing_id' => $listing->id,
                'url' => route('account.questions'),
            ],
            actor: $asker,
        );

        return $question;
    }

    /**
     * الجواب يُكتب مرة ويُعدَّل.
     *
     * answered_at لا يُحرَّك عند التعديل: تاريخ الجواب هو متى أجاب البائع
     * أولاً، وهو ما يقيس سرعة استجابته. تحريكه مع كل تصحيح إملائي يجعل
     * كل بائع يبدو مستجيباً فوراً.
     *
     * والإشعار يتبع نفس القاعدة: التعديل لا يُشعر السائل ثانيةً. إشعارٌ
     * ثانٍ عن نفس الجواب يُقرأ كجوابٍ ثانٍ، فيفتح السائل الصفحة ليجد ما
     * قرأه أمس.
     */
    public function answer(ListingQuestion $question, string $answer, ?User $answerer = null): ListingQuestion
    {
        $isFirstAnswer = $question->answered_at === null;

        $question->forceFill([
            'answer' => $answer,
            'answered_at' => $question->answered_at ?? now(),
        ])->save();

        if ($isFirstAnswer && $question->asker !== null) {
            $listing = $question->listing;

            $this->notifier->send(
                recipient: $question->asker,
                type: NotificationType::QuestionAnswered,
                title: 'وصل جواب سؤالك عن: '.($listing?->title ?? 'إعلان محذوف'),
                body: Str::limit($answer, self::EXCERPT),
                data: [
                    'entity_type' => 'listing_question',
                    'entity_id' => $question->id,
                    'listing_id' => $question->listing_id,
                    'url' => $listing === null ? null : route('listing.show', $listing),
                ],
                actor: $answerer,
            );
        }

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
