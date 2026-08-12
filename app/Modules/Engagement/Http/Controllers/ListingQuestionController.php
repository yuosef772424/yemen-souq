<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\AnswerQuestionRequest;
use App\Modules\Engagement\Http\Requests\AskQuestionRequest;
use App\Modules\Engagement\Models\ListingQuestion;
use App\Modules\Engagement\Services\QuestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ListingQuestionController
{
    /** أسئلة في الساعة لكل مستخدم */
    private const RATE_LIMIT = 10;

    public function __construct(
        private readonly QuestionService $questions,
    ) {
    }

    public function store(AskQuestionRequest $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();

        if (! $this->questions->canAsk($user, $listing)) {
            return back()->with('error', 'لا يمكن طرح سؤال على هذا الإعلان.');
        }

        $key = "questions:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            throw ValidationException::withMessages([
                'body' => 'أسئلة كثيرة في وقت قصير. انتظر قليلاً ثم أعد المحاولة.',
            ]);
        }

        RateLimiter::hit($key, 3600);

        $this->questions->ask($user, $listing, $request->string('body')->value());

        return back()->with('status', 'أُرسل سؤالك. سيظهر جواب البائع هنا للجميع.');
    }

    public function answer(AnswerQuestionRequest $request, ListingQuestion $question): RedirectResponse
    {
        Gate::authorize('answer', $question);

        // المُجيب يُمرَّر صراحةً لا يُشتقّ من مالك الإعلان: Notifier يرفض
        // إشعار الفاعل بفعله، وهو حارس لا يعمل إن لم يعرف من الفاعل
        $this->questions->answer(
            $question,
            $request->string('answer')->value(),
            $request->user(),
        );

        return back()->with('status', 'نُشر جوابك.');
    }

    /**
     * الحذف للسائل وحده.
     *
     * لا صلاحية للمالك — انظر ListingQuestionPolicy. ومن يجرّب المسار
     * بمعرّف سؤال على إعلانه يحصل على 403، لا على حذف صامت.
     */
    public function destroy(ListingQuestion $question): RedirectResponse
    {
        Gate::authorize('delete', $question);

        $question->delete();

        return back()->with('status', 'حُذف سؤالك.');
    }

    /**
     * أسئلة تنتظر جوابي، عبر كل إعلاناتي.
     *
     * لماذا صفحة مستقلة: سؤال بلا جواب على إعلان لا يزوره المالك يبقى
     * معلّقاً إلى الأبد، ويقرأه كل زائر كإشارة إلى بائع لا يردّ. القائمة
     * المجمّعة هي ما يجعل الميزة تعمل فعلاً بدل أن تكون حقلاً في صفحة.
     */
    public function inbox(Request $request): View
    {
        $questions = ListingQuestion::query()
            ->visible()
            ->whereIn('listing_id', function ($query) use ($request): void {
                $query->select('id')
                    ->from('listings')
                    ->whereNull('deleted_at')
                    ->where('user_id', $request->user()->id);
            })
            ->with(['listing', 'asker'])
            ->orderByRaw('answered_at is null desc')
            ->latest('created_at')
            ->paginate(20);

        return view('engagement::questions-inbox', ['questions' => $questions]);
    }
}