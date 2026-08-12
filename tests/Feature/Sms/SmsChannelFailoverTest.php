<?php

namespace Tests\Feature\Sms;

use App\Modules\Sms\Contracts\WhatsAppTransport;
use App\Modules\Sms\Enums\DeliveryChannel;
use App\Modules\Sms\Enums\SmsStatus;
use App\Modules\Sms\Jobs\DeliverWhatsApp;
use App\Modules\Sms\Models\SmsMessage;
use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * القناة الثانية: واتساب بديلاً عند فشل الرسالة النصية.
 *
 * أثقل ما هنا اختباران: أن التراجع يحمل **نفس الرمز** لا رمزاً جديداً،
 * وأن الرقم الذي فشل مراراً يبدأ من البديلة مباشرةً.
 */
class SmsChannelFailoverTest extends TestCase
{
    use RefreshDatabase;

    private SmsOutbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = app(SmsOutbox::class);

        config([
            'sms.failover.sms' => 'whatsapp',
            'sms.failover.whatsapp' => null,
        ]);
    }

    #[Test]
    public function a_message_defaults_to_the_sms_channel(): void
    {
        $message = $this->outbox->queue('+967771234567', 'رمز التحقق: 123456');

        $this->assertSame(DeliveryChannel::Sms, $message->channel);
    }

    /** الهاتف يسحب SMS وحدها — لا يرى صفوف واتساب إطلاقاً */
    #[Test]
    public function a_phone_worker_never_claims_a_whatsapp_row(): void
    {
        Queue::fake();

        $this->outbox->queue('+967771111111', 'أ', 'general', now()->addHour(), DeliveryChannel::WhatsApp);

        $this->assertCount(0, $this->outbox->claim('phone-a', 5, DeliveryChannel::Sms)['messages']);
        $this->assertCount(1, $this->outbox->claim('dispatcher', 5, DeliveryChannel::WhatsApp)['messages']);
    }

    /**
     * أهمّ اختبار في الملف.
     *
     * التراجع ينسخ الجسد كما هو ولا ينادي issue: نداءٌ جديد يُبطل الرمز
     * السابق — وقد يكون وصل عبر SMS فعلاً بعد أن انقطع التأكيد. فيكتب
     * المستخدم ما وصله ويُرفض.
     */
    #[Test]
    public function failing_over_carries_the_very_same_code(): void
    {
        Queue::fake();

        config(['sms.max_attempts' => 1]);

        $original = $this->outbox->queue('+967771234567', 'رمز التحقق: 123456', 'phone_verification', now()->addHour());
        $claim = $this->outbox->claim('phone-a');

        $this->outbox->markFailed($original->id, $claim['claim_token'], 'no service');

        $twin = SmsMessage::query()->where('id', '!=', $original->id)->sole();

        $this->assertSame(DeliveryChannel::WhatsApp, $twin->channel);
        $this->assertSame('رمز التحقق: 123456', $twin->body);
        $this->assertSame($original->id, $twin->failed_over_from);
        $this->assertSame('phone_verification', $twin->purpose);
    }

    /** الأجل موروث لا مُجدَّد: ما بقي من عمر الرمز هو ما بقي */
    #[Test]
    public function the_twin_inherits_the_original_deadline(): void
    {
        Queue::fake();

        config(['sms.max_attempts' => 1]);

        $deadline = now()->addMinutes(4);
        $original = $this->outbox->queue('+967771234567', 'أ', 'general', $deadline);
        $claim = $this->outbox->claim('phone-a');

        $this->travel(60)->seconds();
        $this->outbox->markFailed($original->id, $claim['claim_token'], 'no service');

        $twin = SmsMessage::query()->where('id', '!=', $original->id)->sole();

        $this->assertEqualsWithDelta($deadline->timestamp, $twin->expires_at->timestamp, 2);
    }

    /** رمزٌ مات لا يُعاد إرساله على قناة أخرى — يصل ميتاً */
    #[Test]
    public function an_expired_message_does_not_fail_over(): void
    {
        Queue::fake();

        config(['sms.max_attempts' => 1]);

        $original = $this->outbox->queue('+967771234567', 'أ', 'general', now()->addSeconds(30));
        $claim = $this->outbox->claim('phone-a');

        $this->travel(60)->seconds();
        $this->outbox->markFailed($original->id, $claim['claim_token'], 'no service');

        $this->assertDatabaseCount('sms_messages', 1);
    }

    /** قفزة واحدة لا سلسلة: فشلُ واتساب لا يعود إلى SMS الذي فشل أصلاً */
    #[Test]
    public function the_chain_stops_after_one_hop(): void
    {
        Queue::fake();

        config(['sms.max_attempts' => 1]);

        $original = $this->outbox->queue('+967771234567', 'أ', 'general', now()->addHour());
        $first = $this->outbox->claim('phone-a');
        $this->outbox->markFailed($original->id, $first['claim_token'], 'no service');

        $twin = SmsMessage::query()->where('id', '!=', $original->id)->sole();
        $second = $this->outbox->claim('dispatcher', 5, DeliveryChannel::WhatsApp);
        $this->outbox->markFailed($twin->id, $second['claim_token'], 'no whatsapp');

        $this->assertDatabaseCount('sms_messages', 2);
        $this->assertSame(SmsStatus::Failed, $twin->fresh()->status);
    }

    /** والسحبة الميتة المستنفَدة تتراجع كذلك — لا التأكيد وحده */
    #[Test]
    public function a_reaped_exhausted_message_fails_over_too(): void
    {
        Queue::fake();

        config(['sms.max_attempts' => 1]);

        $this->outbox->queue('+967771234567', 'أ', 'general', now()->addHour());
        $this->outbox->claim('phone-a');

        $this->travel(config('sms.worker.claim_ttl_seconds') + 10)->seconds();

        $result = $this->outbox->reap();

        $this->assertSame(1, $result['failed_over']);
        $this->assertDatabaseHas('sms_messages', ['channel' => 'whatsapp', 'status' => 'queued']);
    }

    // ─── الرقم المحظور ───

    /**
     * الحظر يُتعلَّم من التاريخ: لا إشارة من المشغّل تقول «هذا الرقم
     * محظور»، وما يصلنا فشلٌ متكرّر.
     */
    #[Test]
    public function a_number_that_keeps_failing_starts_on_the_alternative(): void
    {
        Queue::fake();

        config(['sms.blocked_after' => 2]);

        foreach ([1, 2] as $round) {
            SmsMessage::query()->create([
                'to' => '+967771234567',
                'body' => 'أ',
                'channel' => DeliveryChannel::Sms,
                'expires_at' => now()->addHour(),
            ])->forceFill(['status' => SmsStatus::Failed])->save();
        }

        $this->assertSame(
            DeliveryChannel::WhatsApp,
            $this->outbox->preferredChannelFor('+967771234567'),
        );

        $fresh = $this->outbox->queue('+967771234567', 'ب');
        $this->assertSame(DeliveryChannel::WhatsApp, $fresh->channel);
    }

    /** ونجاحٌ واحد بينها يكسر السلسلة — «متتالية» لا «مجموع» */
    #[Test]
    public function one_success_in_between_clears_the_suspicion(): void
    {
        config(['sms.blocked_after' => 2]);

        foreach ([SmsStatus::Failed, SmsStatus::Failed, SmsStatus::Sent] as $status) {
            SmsMessage::query()->create([
                'to' => '+967771234567',
                'body' => 'أ',
                'channel' => DeliveryChannel::Sms,
                'expires_at' => now()->addHour(),
            ])->forceFill(['status' => $status])->save();
        }

        $this->assertSame(
            DeliveryChannel::Sms,
            $this->outbox->preferredChannelFor('+967771234567'),
        );
    }

    #[Test]
    public function a_number_with_no_history_uses_the_default(): void
    {
        $this->assertSame(
            DeliveryChannel::Sms,
            $this->outbox->preferredChannelFor('+967779999999'),
        );
    }

    // ─── مهمّة الدفع ───

    #[Test]
    public function queueing_a_whatsapp_message_dispatches_its_job(): void
    {
        Queue::fake();

        $this->outbox->queue('+967771234567', 'أ', 'general', now()->addHour(), DeliveryChannel::WhatsApp);

        Queue::assertPushed(DeliverWhatsApp::class);
    }

    /** وSMS لا تُدفَع: الهاتف يسحبها، ومهمّةٌ لها تعني عاملين يتنازعان */
    #[Test]
    public function queueing_an_sms_dispatches_nothing(): void
    {
        Queue::fake();

        $this->outbox->queue('+967771234567', 'أ');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_job_marks_the_message_sent_when_the_transport_accepts(): void
    {
        $this->mockTransport(fails: false);

        $message = $this->outbox->queue('+967771234567', 'رمز التحقق: 123456', 'phone_verification', now()->addHour(), DeliveryChannel::WhatsApp);

        $this->assertSame(SmsStatus::Sent, $message->fresh()->status);
    }

    /**
     * الفشل يُبلَّغ للطابور لا يُرمى: رميُ الاستثناء يجعل الطابور يعيد
     * المهمّة بمنطقه هو، فيصير للإعادة مصدران وتُحرق المحاولات مرتين.
     */
    #[Test]
    public function a_transport_failure_returns_the_message_to_the_queue(): void
    {
        $this->mockTransport(fails: true);

        $message = $this->outbox->queue('+967771234567', 'أ', 'general', now()->addHour(), DeliveryChannel::WhatsApp);

        $fresh = $message->fresh();

        $this->assertSame(SmsStatus::Queued, $fresh->status);
        $this->assertSame('الرقم لا يملك واتساب', $fresh->error);
        $this->assertSame(1, $fresh->attempts);
    }

    /** والقالب يحتاج الرمز وحده لا النصّ — ينُتزع من الجسد */
    #[Test]
    public function the_transport_receives_the_bare_code_for_templates(): void
    {
        $recorder = new class implements WhatsAppTransport
        {
            public ?string $code = null;

            public function send(string $to, string $text, ?string $code = null): void
            {
                $this->code = $code;
            }
        };

        $this->app->instance(WhatsAppTransport::class, $recorder);

        $this->outbox->queue('+967771234567', 'رمز التحقق: 482910', 'phone_verification', now()->addHour(), DeliveryChannel::WhatsApp);

        $this->assertSame('482910', $recorder->code);
    }

    private function mockTransport(bool $fails): void
    {
        $this->app->bind(WhatsAppTransport::class, fn (): WhatsAppTransport => new class($fails) implements WhatsAppTransport
        {
            public function __construct(private readonly bool $fails) {}

            public function send(string $to, string $text, ?string $code = null): void
            {
                if ($this->fails) {
                    throw new RuntimeException('الرقم لا يملك واتساب');
                }
            }
        });
    }
}
