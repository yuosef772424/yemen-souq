<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\ConversationResource;
use App\Modules\Api\Http\Resources\MessageResource;
use App\Modules\Api\Support\Pagination;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\SendMessageRequest;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * المحادثات الخاصة.
 *
 * ═══ الوسم مقروءاً فعلٌ صريح لا أثر جانبي للقراءة ═══
 *
 * الويب يسم عند فتح الصفحة، وهو صحيح هناك: الفتح فعلٌ واعٍ. والتطبيق
 * يختلف — قد يجلب الخيط مسبقاً في الخلفية، أو يعيد الجلب عند استعادة
 * الشاشة. فوسمٌ ضمنيّ في `GET` يجعل الإشعار يختفي عن شاشة لم يرها أحد.
 *
 * ولذلك: `GET /conversations/{id}` يقرأ ولا يسم، و
 * `POST /conversations/{id}/read` يسم. مؤشّر القراءة يُبنى عليه سلوك
 * (أعيد الإرسال أم أنتظر)، فكذبُه أسوأ من غيابه.
 *
 * ═══ الرسائل مرقَّمة والأحدث أولاً ═══
 *
 * علاقة `messages` في الموديل مرتّبة تصاعدياً لأن القالب يعرض الخيط
 * كاملاً. والتطبيق يعرض الأحدث ثم يمرّر إلى الأعلى، وخيطٌ فيه ألف رسالة
 * لا يُرسَل دفعةً في استجابة واحدة.
 *
 * ═══ وحدّ الإرسال بنفس مفتاح الويب ═══
 *
 * `messages:{user}` — الرسائل الخاصة أرخص طريق للسبام في أي منصة
 * إعلانات: حساب واحد يبعث نفس النص إلى مئة معلن. والميزانية للحساب لا
 * للقناة. والحدّ في ConversationService لا هنا: التكرار بمعرّف موجود
 * لا يُحتسب عليه.
 *
 * ═══ إعادة الإرسال بـ client_id ═══
 *
 * الحقل اختياري. مع معرّف مكرر تعود الرسالة نفسها بـ200 بدل 201، بلا
 * صفّ جديد ولا إشعار ثانٍ. وفي إنشاء المحادثة تُرجَع الرسالة في
 * `sent_message` بجانب `data` حتى لا يتغير شكل الاستجابة القائم.
 */
class ConversationController
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = Conversation::query()
            ->forUser($request->user())
            // العدّ في نفس الاستعلام — انظر Conversation::scopeWithUnreadCountFor
            ->withUnreadCountFor($request->user())
            ->with(['listing.images', 'listing.currency', 'listing.category', 'listing.location', 'buyer', 'seller'])
            ->orderByDesc('last_message_at')
            ->paginate(Pagination::perPage($request));

        return ConversationResource::collection($conversations);
    }

    /** بيانات الخيط بلا رسائله — الرسائل مسار مرقَّم مستقل */
    public function show(Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $conversation->load(['listing.images', 'listing.currency', 'listing.category', 'listing.location', 'buyer', 'seller']);

        return response()->json([
            'data' => new ConversationResource($conversation),
        ]);
    }

    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        $messages = $conversation->messages()
            ->with('sender')
            ->reorder()
            ->orderByDesc('created_at')
            ->paginate(Pagination::perPage($request));

        return MessageResource::collection($messages);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $marked = $this->conversations->markRead($conversation, $request->user());

        return response()->json([
            'message' => 'وُسمت الرسائل مقروءة.',
            'marked' => $marked,
        ]);
    }

    /**
     * أول رسالة من صفحة الإعلان — أو متابعة الخيط القائم.
     *
     * `canStart` تُفحص هنا لا في Policy: الشرط حالةُ إعلان لا ملكية،
     * ووضعه في Policy يجعل «الإعلان انتهى» تصل كـ403 بلا تفسير.
     *
     * والخدمة `firstOrCreate`: زرٌّ يُضغط مرتين، أو مشترٍ يعود بعد
     * أسبوع، لا يجوز أن يُنتج خيطين للحديث نفسه.
     */
    public function store(SendMessageRequest $request, Listing $listing): JsonResponse
    {
        $user = $request->user();

        if (! $this->conversations->canStart($user, $listing)) {
            return response()->json([
                'message' => 'لا يمكن بدء محادثة على هذا الإعلان.',
                'code' => 'conversation_not_allowed',
            ], 422);
        }

        $sent = $this->conversations->startOrContinue(
            $user,
            $listing,
            (string) $request->validated('body'),
            $request->clientId(),
        );

        $conversation = $sent->conversation;
        $conversation->load(['listing.images', 'listing.currency', 'listing.category', 'listing.location', 'buyer', 'seller']);

        // 201 للإنشاء و200 لإعادة المحاولة بالمعرّف نفسه — والرسالة
        // نفسها بالمعرّف نفسه في الحالتين
        return response()->json([
            'message' => 'أُرسلت رسالتك.',
            'data' => new ConversationResource($conversation),
            'sent_message' => new MessageResource($sent->message),
        ], $sent->created ? 201 : 200);
    }

    public function reply(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $sent = $this->conversations->reply(
            $conversation,
            $request->user(),
            (string) $request->validated('body'),
            $request->clientId(),
        );

        return response()->json([
            'data' => new MessageResource($sent->message),
        ], $sent->created ? 201 : 200);
    }
}
