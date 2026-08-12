<?php

namespace Tests\Feature\Sms;

use App\Modules\Sms\Enums\SmsStatus;
use App\Modules\Sms\Models\SmsMessage;
use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الطابور نفسه — بلا HTTP.
 *
 * أثقل ما هنا اختباران: السحب المتزامن، واستعادة السحبة الميتة. الأول
 * يمنع إرسالاً مزدوجاً، والثاني يمنع رسالة تختفي إلى الأبد. وكلاهما
 * عطلٌ لا يظهر في الاستعمال العادي — يظهر يوم يموت الهاتف.
 */
class SmsOutboxTest extends TestCase
{
    use RefreshDatabase;

    private SmsOutbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = app(SmsOutbox::class);
    }

    #[Test]
    public function it_queues_a_message_with_an_expiry(): void
    {
        $message = $this->outbox->queue('+967771234567', 'رمز التحقق: 123456', 'phone_verification');

        $this->assertSame(SmsStatus::Queued, $message->status);
        $this->assertSame(0, $message->attempts);
        $this->assertNotNull($message->expires_at, 'رسالة بلا أجل تخرج بعد يومين برمز ميت');
    }

    #[Test]
    public function claiming_hands_out_the_oldest_first(): void
    {
        $first = $this->outbox->queue('+967771111111', 'أ');
        $second = $this->outbox->queue('+967772222222', 'ب');

        $claim = $this->outbox->claim('phone-a', 1);

        $this->assertCount(1, $claim['messages']);
        $this->assertSame($first->id, $claim['messages']->first()->id);
        $this->assertNotSame($second->id, $claim['messages']->first()->id);
    }

    /**
     * أهم اختبار في الملف.
     *
     * قارئان — هاتفان، أو نفس الهاتف بعد أن انقطعت شبكته وأعاد النداء —
     * لا يجوز أن يحصلا على نفس الصف. وإلا وصل الرمز مرتين: بكلفة رسالة
     * ثانية، وبارتباكِ من لا يعرف أيّ الرمزين يكتب.
     */
    #[Test]
    public function two_workers_never_get_the_same_message(): void
    {
        $this->outbox->queue('+967771111111', 'أ');
        $this->outbox->queue('+967772222222', 'ب');

        $first = $this->outbox->claim('phone-a', 5);
        $second = $this->outbox->claim('phone-b', 5);

        $this->assertCount(2, $first['messages']);
        $this->assertCount(0, $second['messages'], 'السحبة الثانية أخذت ما سُحب أصلاً');
        $this->assertNull($second['claim_token']);
    }

    /** السحب يزيد العدّاد — لا الفشل. هاتفٌ يموت بعد السحب يحرق محاولة */
    #[Test]
    public function claiming_burns_an_attempt(): void
    {
        $message = $this->outbox->queue('+967771111111', 'أ');

        $this->outbox->claim('phone-a');

        $this->assertSame(1, $message->fresh()->attempts);
    }

    #[Test]
    public function an_expired_message_is_never_claimed(): void
    {
        $this->outbox->queue('+967771111111', 'أ', 'general', now()->subMinute());

        $claim = $this->outbox->claim('phone-a');

        $this->assertCount(0, $claim['messages']);
        $this->assertSame(SmsStatus::Expired, SmsMessage::first()->status);
    }

    #[Test]
    public function confirming_marks_it_sent_and_wipes_the_body(): void
    {
        $message = $this->outbox->queue('+967771111111', 'رمز التحقق: 123456');
        $claim = $this->outbox->claim('phone-a');

        $this->assertTrue($this->outbox->markSent($message->id, $claim['claim_token']));

        $fresh = $message->fresh();

        $this->assertSame(SmsStatus::Sent, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        // رمزٌ صريح باقٍ في جدول يجعل قراءة الجدول كافيةً لتوثيق أي رقم
        $this->assertNull($fresh->body);
    }

    #[Test]
    public function the_body_survives_when_redaction_is_off(): void
    {
        config(['sms.redact_body_on_send' => false]);

        $message = $this->outbox->queue('+967771111111', 'رمز التحقق: 123456');
        $claim = $this->outbox->claim('phone-a');

        $this->outbox->markSent($message->id, $claim['claim_token']);

        $this->assertSame('رمز التحقق: 123456', $message->fresh()->body);
    }

    /**
     * تأكيدٌ بلا رمز سحبةٍ صحيح لا يُغيّر شيئاً.
     *
     * الحالة: هاتفٌ ماتت سحبته واستُعيدت رسالته، ثم استيقظ وأكّد. بلا
     * هذا الشرط يكتب `sent` على سحبةٍ يملكها هاتف آخر.
     */
    #[Test]
    public function a_stale_confirmation_is_ignored(): void
    {
        $message = $this->outbox->queue('+967771111111', 'أ');
        $this->outbox->claim('phone-a');

        $this->assertFalse($this->outbox->markSent($message->id, '00000000-0000-4000-8000-000000000000'));
        $this->assertSame(SmsStatus::Claimed, $message->fresh()->status);
    }

    /**
     * الحالة الغالبة على أندرويد: العملية تُقتل بعد السحب وقبل التأكيد.
     *
     * وبلا استعادة تبقى الرسالة `claimed` إلى الأبد — لا تُرسَل، ولا
     * تظهر في الطابور، ولا يشتكي إلا من لم يصله شيء.
     */
    #[Test]
    public function a_dead_claim_returns_to_the_queue(): void
    {
        $message = $this->outbox->queue('+967771111111', 'أ', 'general', now()->addHour());
        $this->outbox->claim('phone-a');

        $this->travel(config('sms.worker.claim_ttl_seconds') + 10)->seconds();

        $result = $this->outbox->reap();

        $this->assertSame(1, $result['reclaimed']);
        $this->assertSame(SmsStatus::Queued, $message->fresh()->status);
        // والمحاولة محسوبة رغم أن الهاتف لم يؤكّد شيئاً
        $this->assertSame(1, $message->fresh()->attempts);
    }

    /** هاتفٌ يموت عند نفس الرسالة ثلاث مرات لن ينجح في الرابعة */
    #[Test]
    public function a_message_that_exhausts_its_attempts_stops_circulating(): void
    {
        $message = $this->outbox->queue('+967771111111', 'أ', 'general', now()->addDay());

        for ($i = 0; $i < config('sms.max_attempts'); $i++) {
            $this->outbox->claim('phone-a');
            $this->travel(config('sms.worker.claim_ttl_seconds') + 10)->seconds();
            $this->outbox->reap();
        }

        $fresh = $message->fresh();

        $this->assertSame(SmsStatus::Failed, $fresh->status);
        $this->assertNotNull($fresh->failed_at);
        $this->assertCount(0, $this->outbox->claim('phone-a')['messages']);
    }

    #[Test]
    public function a_reported_failure_returns_it_to_the_queue(): void
    {
        $message = $this->outbox->queue('+967771111111', 'أ', 'general', now()->addHour());
        $claim = $this->outbox->claim('phone-a');

        $this->outbox->markFailed($message->id, $claim['claim_token'], 'no service');

        $fresh = $message->fresh();

        $this->assertSame(SmsStatus::Queued, $fresh->status);
        $this->assertSame('no service', $fresh->error);
        $this->assertCount(1, $this->outbox->claim('phone-a')['messages']);
    }

    #[Test]
    public function the_last_failure_ends_it(): void
    {
        config(['sms.max_attempts' => 1]);

        $message = $this->outbox->queue('+967771111111', 'أ', 'general', now()->addHour());
        $claim = $this->outbox->claim('phone-a');

        $this->outbox->markFailed($message->id, $claim['claim_token'], 'SIM missing');

        $this->assertSame(SmsStatus::Failed, $message->fresh()->status);
    }

    /** العدد لا يكفي: طابورٌ فيه ثلاث رسائل عمر أقدمها ساعة يعني هاتفاً متوقفاً */
    #[Test]
    public function stats_report_the_age_of_the_oldest_queued_message(): void
    {
        $this->outbox->queue('+967771111111', 'أ', 'general', now()->addDay());

        $this->travel(90)->seconds();

        $stats = $this->outbox->stats();

        $this->assertSame(1, $stats['queued']);
        $this->assertGreaterThanOrEqual(89, $stats['oldest_queued_seconds']);
    }

    #[Test]
    public function pruning_removes_only_finished_rows(): void
    {
        $done = $this->outbox->queue('+967771111111', 'أ', 'general', now()->addDay());
        $claim = $this->outbox->claim('phone-a');
        $this->outbox->markSent($done->id, $claim['claim_token']);

        $waiting = $this->outbox->queue('+967772222222', 'ب', 'general', now()->addDay());

        $this->travel(8)->days();

        $this->assertSame(1, $this->outbox->prune());
        $this->assertNull($done->fresh());
        $this->assertNotNull($waiting->fresh());
    }
}
