<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\DeliverPush;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Support\Str;

/**
 * المدخل الوحيد لكل إشعار في النظام.
 *
 * ═══ لماذا خدمة واحدة ═══
 *
 * نفس حجّة AuditLogger: القواعد التي تُطبَّق هنا — لا تُشعر الفاعلَ
 * بفعله، احترم التفضيل، اطوِ المكرّر، هدّئ الدفع، اقصص ما يتجاوز العمود —
 * لو وُزّعت على كل موضع يُنشئ إشعاراً لسقطت واحدة منها في أول موضع
 * جديد. وسقوطها لا يُنتج خطأً بل سلوكاً خاطئاً: مستخدم يُشعَر برسالة
 * أرسلها هو، أو أربعون اهتزازة من خيط واحد.
 *
 * ═══ الصفّ متزامن والدفع في طابور ═══
 *
 * الصفّ يُكتب في الطلب نفسه لأنه السجلّ: من يفتح التطبيق بعد ثانية يجب
 * أن يجده. والدفع يُدفَع إلى طابور لأنه نداءُ شبكةٍ إلى مزوّد خارجي —
 * ووضعه في مسار الطلب يعني أن بطء FCM يصير بطءاً في إرسال رسالة.
 *
 * وفشل الدفع لا يُفشل شيئاً: الإشعار موجود، وغاية الدفع تنبيهٌ فوق ذلك.
 *
 * ═══ الفاعل لا يُشعَر بفعله ═══
 *
 * الحارس الأول، وهو أكثر ما يُنسى: البائع يردّ في محادثة، فيصل الإشعار
 * إليه لا إلى المشتري، لأن المستدعي مرّر «صاحب المحادثة» بدل «الطرف
 * الآخر». تمرير actor يجعل الخطأ يُلتقط هنا مرة واحدة بدل أن يتكرّر في
 * كل مستدعٍ.
 */
final class Notifier
{
    /** حدود الأعمدة في المخطط — القصّ هنا لا في قاعدة البيانات */
    private const TITLE_LIMIT = 180;

    private const BODY_LIMIT = 500;

    public function __construct(
        private readonly PreferenceResolver $preferences,
        private readonly DeviceRegistrar $devices,
    ) {}

    /**
     * @param  array<string, mixed>  $data  حمولة التنقّل — entity_type وentity_id وurl
     * @param  ?User  $actor  من قام بالفعل؛ لا يُشعَر به
     */
    public function send(
        User $recipient,
        NotificationType $type,
        string $title,
        ?string $body = null,
        array $data = [],
        ?User $actor = null,
    ): ?AppNotification {
        if ($actor !== null && $actor->id === $recipient->id) {
            return null;
        }

        if (! $this->preferences->allows($recipient, $type, Channel::InApp)) {
            return null;
        }

        $notification = $this->record($recipient, $type, $title, $body, $data);

        $this->push($recipient, $notification);

        return $notification;
    }

    /**
     * كتابة الصفّ — إمّا جديد أو طيّ فوق غير مقروء.
     *
     * الطيّ يحدّث النصّ والتاريخ ولا يمسّ sent_at: العمود هو ذاكرة
     * التهدئة، وتصفيره مع كل رسالة يُبطل التهدئة تماماً — وهي بالضبط
     * الحالة التي وُجدت لأجلها.
     */
    private function record(
        User $recipient,
        NotificationType $type,
        string $title,
        ?string $body,
        array $data,
    ): AppNotification {
        $title = Str::limit($title, self::TITLE_LIMIT - 1, '…');
        $body = $body === null ? null : Str::limit($body, self::BODY_LIMIT - 1, '…');

        $dedupeKey = $this->dedupeKey($type, $data);

        if ($dedupeKey !== null) {
            $existing = AppNotification::query()
                ->forUser($recipient)
                ->where('dedupe_key', $dedupeKey)
                ->unread()
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'created_at' => now(),
                ])->save();

                return $existing;
            }
        }

        return AppNotification::create([
            'user_id' => $recipient->id,
            'type' => $type,
            'dedupe_key' => $dedupeKey,
            'title' => $title,
            'body' => $body,
            'data' => $data === [] ? null : $data,
            'channel' => Channel::InApp,
            'created_at' => now(),
        ]);
    }

    /**
     * المفتاح يُشتقّ ولا يُمرَّر.
     *
     * لو كان وسيطاً لبناه كل مستدعٍ بصيغته، وأول اختلاف في الصيغة يُنتج
     * مفتاحين لنفس الخيط — فيعود الطيّ إلى لا شيء بلا أن يلاحظ أحد.
     */
    private function dedupeKey(NotificationType $type, array $data): ?string
    {
        if (! $type->collapses()) {
            return null;
        }

        $entityType = $data['entity_type'] ?? null;
        $entityId = $data['entity_id'] ?? null;

        if (! is_string($entityType) || ! is_scalar($entityId)) {
            return null;
        }

        return "{$type->value}:{$entityType}:{$entityId}";
    }

    /**
     * إرسال الدفع — بثلاثة شروط قبله.
     *
     * التهدئة تُقاس على sent_at لا على created_at: الأول متى اهتزّ الجهاز
     * آخر مرة، والثاني متى وقع الحدث. من أرسل عشر رسائل في دقيقة يُنتج
     * دفعاً واحداً ثم صمتاً — والصفّ في القائمة يبقى محدَّثاً على أي حال.
     */
    private function push(User $recipient, AppNotification $notification): void
    {
        if (! $this->preferences->allows($recipient, $notification->type, Channel::Push)) {
            return;
        }

        if ($this->coolingDown($notification)) {
            return;
        }

        $tokens = $this->devices->tokensFor($recipient);

        if ($tokens === []) {
            return;
        }

        DeliverPush::dispatch($notification->id, $tokens);
    }

    private function coolingDown(AppNotification $notification): bool
    {
        if ($notification->sent_at === null) {
            return false;
        }

        $cooldown = (int) config('notifications.push.cooldown_seconds', 60);

        return $notification->sent_at->diffInSeconds(now()) < $cooldown;
    }
}
