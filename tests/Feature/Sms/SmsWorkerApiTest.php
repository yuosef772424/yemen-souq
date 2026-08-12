<?php

namespace Tests\Feature\Sms;

use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الوجه الذي يراه هاتف تيرمكس.
 *
 * أول اختبارين هما المهمّان: نقطة `claim` تُرجع رموز توثيق صريحة، ومن
 * يصل إليها بلا سرّ يوثّق أي حساب في المنصة.
 */
class SmsWorkerApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-worker-secret-that-is-long-enough-to-pass';

    private SmsOutbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sms.worker.token' => self::TOKEN]);

        $this->outbox = app(SmsOutbox::class);
    }

    // ─── الحراسة ───

    #[Test]
    public function the_queue_is_closed_without_the_secret(): void
    {
        $this->outbox->queue('+967771234567', 'رمز التحقق: 123456');

        $this->postJson('/api/v1/sms/claim')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'sms_worker_unauthorized');
    }

    #[Test]
    public function a_wrong_secret_is_refused(): void
    {
        $this->withToken('not-the-secret')
            ->postJson('/api/v1/sms/claim')
            ->assertUnauthorized();
    }

    /**
     * سرٌّ فارغ يرفض كل شيء — لا يقبل كل شيء.
     *
     * بلا هذا الشرط يطابق `hash_equals('', '')` طلباً بلا ترويسة على
     * تهيئةٍ نسيت المفتاح، فينفتح الطابور للعالم بصمت.
     */
    #[Test]
    public function an_unconfigured_secret_closes_the_door_rather_than_opening_it(): void
    {
        config(['sms.worker.token' => '']);

        $this->postJson('/api/v1/sms/claim')->assertUnauthorized();
        $this->withToken('')->postJson('/api/v1/sms/claim')->assertUnauthorized();
    }

    // ─── الدورة الكاملة ───

    #[Test]
    public function a_worker_claims_sends_and_confirms(): void
    {
        $this->outbox->queue('+967771234567', 'رمز التحقق: 123456', 'phone_verification');

        $claim = $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/claim', ['worker' => 'pixel-4a'])
            ->assertOk()
            ->assertJsonStructure(['claim_token', 'claim_expires_in', 'messages' => [['id', 'to', 'body']]])
            ->assertJsonPath('messages.0.to', '+967771234567')
            ->assertJsonPath('messages.0.body', 'رمز التحقق: 123456');

        $id = $claim->json('messages.0.id');

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/confirm', [
                'claim_token' => $claim->json('claim_token'),
                'results' => [['id' => $id, 'status' => 'sent']],
            ])
            ->assertOk()
            ->assertJsonPath('sent', 1)
            ->assertJsonPath('ignored', 0);

        $this->assertDatabaseHas('sms_messages', ['id' => $id, 'status' => 'sent']);
        // واسم العامل مكتوب — ليُعرف أيّ هاتف سحب ماذا
        $this->assertDatabaseHas('sms_messages', ['id' => $id, 'claimed_by' => 'pixel-4a']);
    }

    #[Test]
    public function an_empty_queue_returns_no_claim_token(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/claim')
            ->assertOk()
            ->assertJsonPath('claim_token', null)
            ->assertJsonCount(0, 'messages');
    }

    #[Test]
    public function a_reported_failure_carries_its_reason(): void
    {
        $message = $this->outbox->queue('+967771234567', 'أ', 'general', now()->addHour());

        $claim = $this->withToken(self::TOKEN)->postJson('/api/v1/sms/claim')->assertOk();

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/confirm', [
                'claim_token' => $claim->json('claim_token'),
                'results' => [['id' => $message->id, 'status' => 'failed', 'error' => 'no service']],
            ])
            ->assertOk()
            ->assertJsonPath('failed', 1);

        $this->assertSame('no service', $message->fresh()->error);
    }

    /**
     * `ignored` هو التشخيص الوحيد لسحبةٍ ماتت أثناء الإرسال.
     *
     * رقمٌ يكبر هنا يعني أن `claim_ttl_seconds` أقصر من زمن الإرسال
     * الفعلي على ذلك الهاتف.
     */
    #[Test]
    public function confirming_a_dead_claim_is_counted_as_ignored(): void
    {
        $message = $this->outbox->queue('+967771234567', 'أ', 'general', now()->addDay());

        $claim = $this->withToken(self::TOKEN)->postJson('/api/v1/sms/claim')->assertOk();

        $this->travel(config('sms.worker.claim_ttl_seconds') + 10)->seconds();

        // نداءٌ من هاتف آخر يستعيد السحبة الميتة
        $this->withToken(self::TOKEN)->postJson('/api/v1/sms/claim', ['worker' => 'other'])->assertOk();

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/confirm', [
                'claim_token' => $claim->json('claim_token'),
                'results' => [['id' => $message->id, 'status' => 'sent']],
            ])
            ->assertOk()
            ->assertJsonPath('ignored', 1)
            ->assertJsonPath('sent', 0);
    }

    #[Test]
    public function a_malformed_confirmation_is_rejected(): void
    {
        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/confirm', ['claim_token' => 'not-a-uuid', 'results' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['claim_token', 'results']);
    }

    #[Test]
    public function health_reports_the_queue_without_leaking_any_message(): void
    {
        $this->outbox->queue('+967771234567', 'رمز التحقق: 123456');

        $response = $this->withToken(self::TOKEN)->getJson('/api/v1/sms/health')->assertOk();

        $response->assertJsonPath('data.queued', 1);
        $this->assertStringNotContainsString('123456', $response->getContent());
        $this->assertStringNotContainsString('771234567', $response->getContent());
    }
}
