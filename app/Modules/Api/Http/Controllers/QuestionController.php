<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\QuestionResource;
use App\Modules\Api\Support\Pagination;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\AnswerQuestionRequest;
use App\Modules\Engagement\Http\Requests\AskQuestionRequest;
use App\Modules\Engagement\Models\ListingQuestion;
use App\Modules\Engagement\Services\QuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * الأسئلة العامة على الإعلان.
 *
 * ═══ حارس الأرقام يعمل هنا لأن الطلب مُعاد استعماله ═══
 *
 * `AskQuestionRequest` و`AnswerQuestionRequest` تحملان قاعدة
 * `PublicContactGuard`: لا رقم هاتف في نصّ يظهر للعموم. ولو كُتب طلبٌ
 * جديد للـAPI لسقطت القاعدة بصمت — ولصار التطبيق بابَ التفافٍ على
 * حارسٍ موجود في الويب. هذا بالضبط نوع الثغرة التي تفتحها واجهةٌ ثانية.
 *
 * ═══ والحدّ الزمني بنفس المفتاح ═══
 *
 * `questions:{user}` هو المفتاح في متحكّم الويب. مشتركٌ بقصد، بنفس حجّة
 * `LoginThrottle`: عشرة أسئلة في الساعة ميزانيةُ حسابٍ لا ميزانية قناة.
 */
class QuestionController
{
    /** أسئلة في الساعة لكل مستخدم — نفس حدّ الويب */
    private const RATE_LIMIT = 10;

    public function __construct(
        private readonly QuestionService $questions,
    ) {}

    /** أسئلة إعلان — عامة، بلا رمز */
    public function index(Listing $listing): AnonymousResourceCollection
    {
        abort_unless($listing->status === 'active', 404);

        return QuestionResource::collection(
            $this->questions->forListing($listing)
        );
    }

    public function store(AskQuestionRequest $request, Listing $listing): JsonResponse
    {
        $user = $request->user();

        /**
         * `canAsk` هنا لا في Policy: الشرط حالةُ إعلان (منتهٍ، محذوف،
         * أو للمستخدم نفسه) لا ملكية — ومنطق المجال في Policy يجعل
         * «الإعلان انتهى» تصل كـ403.
         */
        if (! $this->questions->canAsk($user, $listing)) {
            return response()->json([
                'message' => 'لا يمكن طرح سؤال على هذا الإعلان.',
                'code' => 'question_not_allowed',
            ], 422);
        }

        $key = "questions:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            throw ValidationException::withMessages([
                'body' => 'أسئلة كثيرة في وقت قصير. انتظر قليلاً ثم أعد المحاولة.',
            ]);
        }

        RateLimiter::hit($key, 3600);

        $question = $this->questions->ask($user, $listing, (string) $request->validated('body'));

        return response()->json([
            'message' => 'أُرسل سؤالك. سيظهر جواب البائع للجميع.',
            'data' => new QuestionResource($question->load('asker')),
        ], 201);
    }

    public function answer(AnswerQuestionRequest $request, ListingQuestion $question): JsonResponse
    {
        Gate::authorize('answer', $question);

        // المُجيب يُمرَّر صراحةً لا يُشتقّ من مالك الإعلان: Notifier يرفض
        // إشعار الفاعل بفعله، وهو حارس لا يعمل إن لم يعرف من الفاعل
        $question = $this->questions->answer(
            $question,
            (string) $request->validated('answer'),
            $request->user(),
        );

        return response()->json([
            'message' => 'نُشر جوابك.',
            'data' => new QuestionResource($question->load('asker')),
        ]);
    }

    /** الحذف للسائل وحده — لا صلاحية للمالك، انظر ListingQuestionPolicy */
    public function destroy(ListingQuestion $question): JsonResponse
    {
        Gate::authorize('delete', $question);

        $question->delete();

        return response()->json(['message' => 'حُذف سؤالك.']);
    }

    /**
     * أسئلة تنتظر جوابي عبر كل إعلاناتي.
     *
     * سؤال بلا جواب على إعلان لا يزوره صاحبه يبقى معلّقاً، ويقرأه كل
     * زائر كإشارة إلى بائع لا يردّ. القائمة المجمّعة هي ما يجعل الميزة
     * تعمل — والإشعار وحده لا يكفي: من أوقف إشعاراته يفقدها كلها.
     */
    public function inbox(Request $request): AnonymousResourceCollection
    {
        $questions = ListingQuestion::query()
            ->visible()
            ->whereIn('listing_id', function ($query) use ($request): void {
                $query->select('id')
                    ->from('listings')
                    ->whereNull('deleted_at')
                    ->where('user_id', $request->user()->id);
            })
            ->with(['listing.images', 'listing.currency', 'listing.category', 'listing.location', 'asker'])
            ->orderByRaw('answered_at is null desc')
            ->latest('created_at')
            ->paginate(Pagination::perPage($request));

        return QuestionResource::collection($questions);
    }
}
