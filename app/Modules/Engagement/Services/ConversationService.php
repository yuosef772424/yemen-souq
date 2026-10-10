<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Models\Message;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\Notifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * فتح المحادثات وإرسال الرسائل.
 *
 * ═══ قرارات ═══
 *
 * 1. المشتري وحده يفتح المحادثة.
 *    القيد unique(listing_id, buyer_id) يعني أن المالك لو راسل عبر
 *    إعلانه لأنشأ صفاً يكون فيه المشتري والبائع شخصاً واحداً — خيط يحدّث
 *    فيه نفسه، ويظهر في قائمته مرتين. الأسوأ أنه يفسد كل إحصاء عن
 *    «المحادثات المفتوحة لكل إعلان». المنع صريح في canStart.
 *
 * 2. الإعلان المغلق: لا محادثة جديدة، والقائمة تُقرأ وتُجاب.
 *    قطع خيط جارٍ لأن الإعلان انتهى يضرّ الطرفين — قد يكونا في منتصف
 *    اتفاق. أما فتح خيط جديد على إعلان منتهٍ فإرسال المشتري إلى طريق
 *    مسدود. الفرق بين الحالتين هو كل الفرق، ولهذا canStart وحدها تفحص
 *    حالة الإعلان.
 *
 * 3. أول رسالة تُسجَّل حدثاً في التحليلات، وما بعدها لا.
 *    الحدث المفيد هو «تواصل حدث»، لا عدد الرسائل. تسجيل كل رسالة يجعل
 *    محادثةً طويلة واحدة تبدو كخمسين نية شراء.
 *
 * 4. last_message_at على المحادثة تكرار مقصود.
 *    بلاه، ترتيب قائمة المحادثات يحتاج ربطاً بجدول messages وتجميعاً
 *    لكل صف. عمود واحد يُحدَّث مع كل رسالة أرخص بمراحل — وهو أيضاً ما
 *    يفهرسه المخطط أصلاً: index(buyer_id, last_message_at).
 *
 * 5. الإشعار لا يحمل نصّ الرسالة — انظر notify أدناه.
 *
 * 6. إعادة الإرسال آمنة بمعرّف من العميل (client_id).
 *    انقطاع الشبكة بعد وصول الطلب، تحديث الصفحة بعد الإرسال، ضغطة
 *    مزدوجة — كلها تُرسل الطلب نفسه مرتين. فالعميل يولّد معرّفاً لكل
 *    رسالة ويعيد استخدامه عند المحاولة، والمخزن يفرض الفريد على
 *    (محادثة، مرسل، معرّف). ثلاث نتائج لذلك:
 *      - الرسالة الموجودة تُعاد كما هي: لا صفّ ثانٍ ولا إشعار ثانٍ.
 *      - التكرار لا يُحتسب على حدّ المعدل، ولا يُرفض لو بلغ المرسل الحدّ:
 *        هو ليس رسالة جديدة، ورفضه يخبر العميل أن رسالته لم تصل وقد وصلت.
 *      - tooManyAttempts تُفحص قبل الإنشاء، وhit بعد نجاحه: محاولة فاشلة
 *        لا تستهلك الحدّ.
 *    المرجع: deduplicateCreatePost في Mattermost (PendingPostId)،
 *    ومفتاح Idempotency-Key في Stripe. وبلا معرّف (عميل قديم) يبقى
 *    السلوك السابق كما هو.
 */
final class ConversationService
{
    /** رسائل في الدقيقة لكل مستخدم — للويب والـAPI معاً */
    public const RATE_LIMIT = 12;

    public const RATE_WINDOW = 60;

    public function __construct(
        private readonly EventRecorder $events,
        private readonly Notifier $notifier,
    ) {}

    /**
     * هل يجوز لهذا المستخدم فتح محادثة على هذا الإعلان؟
     *
     * تُستخدم في القالب أيضاً: زرّ يظهر ثم يُرفض بعد الضغط أسوأ من زرّ
     * لا يظهر.
     */
    public function canStart(User $user, Listing $listing): bool
    {
        if ($listing->user_id === $user->id) {
            return false;
        }

        if ($listing->trashed() || $listing->status !== 'active' || $listing->isExpired()) {
            return false;
        }

        return true;
    }

    /**
     * إرسال أول رسالة أو متابعة الخيط القائم.
     *
     * firstOrCreate لا create — انظر تعليق Conversation.
     *
     * الترتيب مقصود: بحثٌ عن المعرّف، فحصُ الحدّ، الإنشاء، ثم hit.
     * البحث قبل المعاملة لا داخلها: المسابِق الذي يفوز يكتب في معاملته
     * هو، والخاسر يلتقط مخالفة الفريد ويقرأ الصفّ الفائز بعد تراجع
     * معاملته.
     */
    public function startOrContinue(User $buyer, Listing $listing, string $body, ?string $clientId = null): SentMessage
    {
        $existing = $this->findDuplicateForListing($buyer, $listing, $clientId);

        if ($existing !== null) {
            return $existing;
        }

        $this->guardRate($buyer);

        try {
            [$conversation, $message, $isFirst] = DB::transaction(function () use ($buyer, $listing, $body, $clientId): array {
                $conversation = Conversation::firstOrCreate(
                    ['listing_id' => $listing->id, 'buyer_id' => $buyer->id],
                    ['seller_id' => $listing->user_id, 'created_at' => now()]
                );

                $isFirst = $conversation->wasRecentlyCreated;

                $message = $this->append($conversation, $buyer, $body, $clientId);

                return [$conversation, $message, $isFirst];
            });
        } catch (UniqueConstraintViolationException $e) {
            // طلبان بالمعرّف نفسه مرّا معاً من البحث — الآخر سبق
            return $this->findDuplicateForListing($buyer, $listing, $clientId) ?? throw $e;
        }

        $this->hitRate($buyer);

        if ($isFirst) {
            $this->events->record(EventName::ContactRevealed, 'listing', $listing->id, [
                'category_id' => $listing->category_id,
                'method' => 'message',
            ]);

            // نفس عدّاد كشف الرقم: كلاهما «تواصل حدث»، والتفريق بينهما
            // في حمولة الحدث لا في عمودين منفصلين
            Listing::withTrashed()->whereKey($listing->getKey())->increment('contacts_count');
        }

        return new SentMessage($conversation, $message, true);
    }

    /** رسالة في خيط قائم — من أي من الطرفين */
    public function reply(Conversation $conversation, User $sender, string $body, ?string $clientId = null): SentMessage
    {
        $existing = $this->findDuplicate($conversation, $sender, $clientId);

        if ($existing !== null) {
            return $existing;
        }

        $this->guardRate($sender);

        try {
            $message = DB::transaction(fn (): Message => $this->append($conversation, $sender, $body, $clientId));
        } catch (UniqueConstraintViolationException $e) {
            return $this->findDuplicate($conversation, $sender, $clientId) ?? throw $e;
        }

        $this->hitRate($sender);

        return new SentMessage($conversation, $message, true);
    }

    /**
     * وسم رسائل الطرف الآخر مقروءةً.
     *
     * رسائل المرسل نفسه لا تُوسم: «مقروءة» تعني أن المستقبل رآها، ووسمها
     * للمرسل يجعل مؤشّر القراءة كذباً — وهو مؤشّر يُبنى عليه سلوك (أعيد
     * الإرسال أم أنتظر).
     */
    public function markRead(Conversation $conversation, User $reader): int
    {
        return $conversation->messages()
            ->where('sender_id', '!=', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** الرسالة التي أُرسلت بهذا المعرّف من هذا المرسل في هذا الخيط، إن وُجدت */
    private function findDuplicate(Conversation $conversation, User $sender, ?string $clientId): ?SentMessage
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }

        $message = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', $sender->id)
            ->where('client_id', $clientId)
            ->first();

        return $message === null ? null : new SentMessage($conversation, $message, false);
    }

    private function findDuplicateForListing(User $buyer, Listing $listing, ?string $clientId): ?SentMessage
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }

        $conversation = Conversation::query()
            ->where('listing_id', $listing->id)
            ->where('buyer_id', $buyer->id)
            ->first();

        return $conversation === null ? null : $this->findDuplicate($conversation, $buyer, $clientId);
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
     *
     * والمفتاح واحد للويب والـAPI: الميزانية للحساب لا للقناة.
     */
    private function guardRate(User $sender): void
    {
        if (RateLimiter::tooManyAttempts(self::rateKey($sender), self::RATE_LIMIT)) {
            throw ValidationException::withMessages([
                'body' => 'رسائل كثيرة في وقت قصير. انتظر دقيقة ثم أعد المحاولة.',
            ]);
        }
    }

    private function hitRate(User $sender): void
    {
        RateLimiter::hit(self::rateKey($sender), self::RATE_WINDOW);
    }

    private static function rateKey(User $sender): string
    {
        return "messages:{$sender->id}";
    }

    private function append(Conversation $conversation, User $sender, string $body, ?string $clientId = null): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $body,
            'client_id' => $clientId === '' ? null : $clientId,
            'created_at' => now(),
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        $this->notify($conversation, $sender);

        return $message;
    }

    /**
     * إشعار الطرف الآخر — بلا نصّ الرسالة.
     *
     * ═══ لماذا لا يُنسخ النصّ ═══
     *
     * سببان، وكلاهما يكفي وحده:
     *
     * 1. **الشاشة المقفلة.** المساومة على السعر تصل إلى شاشة يراها من
     *    يقف بجانب صاحب الجهاز. مساومةٌ خاصة تُقرأ من فوق الكتف ليست
     *    تفصيلاً في سوق يتفاوض فيه الناس على مبالغ حقيقية.
     *
     * 2. **نسخة ثانية بعمر مختلف.** نصّ الرسالة يعيش في messages، ونسخه
     *    إلى app_notifications يجعله في جدولين لكلٍّ سياسة حذف وقراءة
     *    مختلفة. وnotifications:prune يحذف بعد تسعين يوماً بينما الرسالة
     *    باقية — فيصير للنصّ الواحد مصيران.
     *
     * وعنوان الإعلان بديلٌ كافٍ: المستقبِل يعرف عن أيّ سلعة يُسأل، وهو
     * ما يحتاجه ليقرّر أيفتح الآن أم لاحقاً. والنصّ على بُعد نقرة.
     *
     * counterpart قد تُرجع null لو حُذف الطرف الآخر — والحذف الناعم على
     * users يجعلها حالة واقعية لا نظرية.
     */
    private function notify(Conversation $conversation, User $sender): void
    {
        $recipient = $conversation->counterpart($sender);

        if ($recipient === null) {
            return;
        }

        $listingTitle = $conversation->listing?->title ?? 'إعلان محذوف';

        $this->notifier->send(
            recipient: $recipient,
            type: NotificationType::NewMessage,
            title: 'رسالة جديدة من '.$sender->displayName(),
            body: 'بخصوص: '.$listingTitle,
            data: [
                'entity_type' => 'conversation',
                'entity_id' => $conversation->id,
                'listing_id' => $conversation->listing_id,
                'url' => route('account.messages.show', $conversation),
            ],
            actor: $sender,
        );
    }
}
