<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Jobs\PersistEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * تسجيل أحداث السلوك.
 *
 * قرارات مقصودة:
 *
 * 1. الأحداث تُجمَّع في الذاكرة أثناء الطلب وتُكتب مرة واحدة عبر طابور
 *    في نهايته. الكتابة المتزامنة لكل حدث تضيف 10-30ms على كل صفحة،
 *    وصفحة بحث واحدة قد تولّد 25 حدثاً (ظهور لكل نتيجة).
 *
 * 2. لا بيانات شخصية في payload إطلاقاً — لا هواتف، لا أسماء، لا نص
 *    رسائل. الحقول المسموحة محدَّدة بوضوح في كل موضع استدعاء.
 *
 * 3. anon_id في كوكي طويل الأمد، مستقل عن user_id. الزائر يتصفح قبل
 *    التسجيل، وربط سجله بحسابه لاحقاً يعطي رحلة كاملة.
 *
 * 4. عنوان IP لا يُخزَّن أبداً — لا كاملاً ولا مقطوعاً. لا نحتاجه.
 */
final class EventRecorder
{
    public const ANON_COOKIE = 'sq_anon';

    private const SESSION_KEY = 'sq_session_id';

    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    public function __construct(
        private readonly Request $request,
    ) {}

    public function record(
        EventName $event,
        ?string $entityType = null,
        ?int $entityId = null,
        array $payload = [],
    ): void {
        if (! $this->allowed()) {
            return;
        }

        $this->buffer[] = [
            'event_name' => $event->value,
            'occurred_at' => now()->format('Y-m-d H:i:s.v'),
            'user_id' => $this->request->user()?->id,
            'anon_id' => $this->anonId(),
            'session_id' => $this->sessionId(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'context' => json_encode($this->context(), JSON_UNESCAPED_UNICODE),
        ];
    }

    /** ظهور عدة نتائج في صفحة بحث واحدة */
    public function recordImpressions(array $listingIds, int $offset = 0): void
    {
        foreach (array_values($listingIds) as $index => $listingId) {
            $this->record(
                EventName::SearchImpression,
                'listing',
                (int) $listingId,
                ['position' => $offset + $index + 1],
            );
        }
    }

    /** يُستدعى في terminate() — انظر AnalyticsServiceProvider */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $rows = $this->buffer;
        $this->buffer = [];

        PersistEvents::dispatch($rows);
    }

    public function anonId(): string
    {
        $existing = $this->request->cookie(self::ANON_COOKIE);

        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }

        // يُثبَّت في كوكي بواسطة AttachAnonymousId middleware
        return $this->request->attributes->get('anon_id')
            ?? tap((string) Str::uuid(), fn (string $id) => $this->request->attributes->set('anon_id', $id));
    }

    private function sessionId(): string
    {
        if (! $this->request->hasSession()) {
            return $this->anonId();
        }

        $session = $this->request->session();

        if (! $session->has(self::SESSION_KEY)) {
            $session->put(self::SESSION_KEY, (string) Str::uuid());
        }

        return $session->get(self::SESSION_KEY);
    }

    /** سياق تقني فقط — لا شيء يعرّف الشخص */
    private function context(): array
    {
        $agent = (string) $this->request->userAgent();

        return [
            'platform' => 'web',
            'mobile' => (bool) preg_match('/Mobile|Android|iPhone/i', $agent),
            'locale' => app()->getLocale(),
        ];
    }

    private function allowed(): bool
    {
        $user = $this->request->user();

        if ($user === null) {
            return true;
        }

        // احترام انسحاب المستخدم من التتبع
        return (bool) ($user->trackingPreferences?->allow_analytics ?? true);
    }
}
