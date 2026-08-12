<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\SendMessageRequest;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Services\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConversationController
{
    /** رسائل في الدقيقة لكل مستخدم */
    private const RATE_LIMIT = 12;

    public function __construct(
        private readonly ConversationService $conversations,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->forUser($user)
            // العدّ في نفس الاستعلام لا استعلاماً لكل صفّ في القالب
            ->withUnreadCountFor($user)
            ->with(['listing.images', 'buyer', 'seller'])
            ->orderByDesc('last_message_at')
            ->paginate(15);

        return view('engagement::conversations.index', [
            'conversations' => $conversations,
            'user' => $user,
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        $user = $request->user();

        // الوسم عند الفتح لا عند الإرسال — انظر ConversationService::markRead
        $this->conversations->markRead($conversation, $user);

        $conversation->load(['messages.sender', 'listing.images', 'buyer', 'seller']);

        return view('engagement::conversations.show', [
            'conversation' => $conversation,
            'user' => $user,
            'counterpart' => $conversation->counterpart($user),
        ]);
    }

    /**
     * أول رسالة من صفحة الإعلان.
     *
     * canStart تُفحص هنا لا في Policy: الشرط ليس ملكيةً بل حالةَ إعلان
     * (منتهٍ، محذوف، أو للمستخدم نفسه) — وهذا منطق مجال لا صلاحية.
     * وضعه في Policy يجعل «الإعلان انتهى» تظهر للمستخدم كـ403.
     */
    public function store(SendMessageRequest $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();

        if (! $this->conversations->canStart($user, $listing)) {
            return back()->with('error', 'لا يمكن بدء محادثة على هذا الإعلان.');
        }

        $this->guardRate($user->id);

        $conversation = $this->conversations->startOrContinue(
            $user,
            $listing,
            $request->string('body')->value()
        );

        return redirect()
            ->route('account.messages.show', $conversation)
            ->with('status', 'أُرسلت رسالتك.');
    }

    public function reply(SendMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('reply', $conversation);

        $this->guardRate($request->user()->id);

        $this->conversations->reply(
            $conversation,
            $request->user(),
            $request->string('body')->value()
        );

        return redirect()->route('account.messages.show', $conversation);
    }

    /**
     * حدّ زمني على الإرسال.
     *
     * الرسائل الخاصة أرخص طريق للسبام في أي منصة إعلانات: حساب واحد يبعث
     * نفس النص إلى مئة معلن. الحدّ لا يمنع الاستخدام الطبيعي — اثنتا عشرة
     * رسالة في الدقيقة أسرع من أي محادثة حقيقية.
     *
     * ValidationException لا abort(429): الرسالة تعود إلى نفس النموذج
     * ويقرأها المستخدم بالعربية، بدل صفحة خطأ لا تقول ما يفعل.
     */
    private function guardRate(int $userId): void
    {
        $key = "messages:{$userId}";

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            throw ValidationException::withMessages([
                'body' => 'رسائل كثيرة في وقت قصير. انتظر دقيقة ثم أعد المحاولة.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}