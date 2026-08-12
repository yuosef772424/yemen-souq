<?php

namespace Tests\Feature\Sms;

use App\Models\User;
use App\Modules\Identity\Services\PhoneVerificationService;
use App\Modules\Sms\Enums\ChallengeResult;
use App\Modules\Sms\Models\PhoneChallenge;
use App\Modules\Sms\Services\ChallengeVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * القناة الثالثة: العميل يرسل إلينا.
 *
 * ═══ أخطر اختبار هنا هو العزل ═══
 *
 * الرمز في هذه القناة **معروض على الشاشة** — لأن صاحبه يجب أن يرسله.
 * ولو قبِله نموذج `/verify-phone` لانهار التوثيق العادي كله: يوثّق أيُّ
 * أحد أيَّ رقم بقراءة شاشته هو.
 *
 * والعزل يقع بمخزنين مستقلين — `phone_challenges` و`phone_verifications` —
 * لا بشرطٍ في الكود. والاختبار يثبّته.
 */
class ReverseVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-worker-secret-that-is-long-enough-to-pass';

    private ChallengeVerifier $challenges;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sms.worker.token' => self::TOKEN,
            'sms.inbound.enabled' => true,
            'sms.inbound.number' => '0770000000',
            'sms.inbound.prefix' => 'SQ',
        ]);

        $this->challenges = app(ChallengeVerifier::class);

        $this->user = User::factory()->create([
            'phone' => '+967771234567',
            'phone_verified_at' => null,
        ]);
    }

    // ─── العزل بين المخزنين ───

    /**
     * أهمّ اختبار في الملف.
     *
     * رمز التحدّي معروض على الشاشة. ولو قبله نموذج التوثيق العادي لصار
     * عرضُه ثغرةً كاملة — والعزل بالمخزنين هو ما يمنع ذلك.
     */
    #[Test]
    public function the_displayed_challenge_token_is_rejected_by_the_normal_form(): void
    {
        $challenge = $this->challenges->issue($this->user);

        $this->actingAs($this->user)
            ->post('/verify-phone', ['code' => $challenge['token']])
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->user->fresh()->hasVerifiedPhone());
    }

    /** والعكس: رمز التوثيق العادي لا يوثّق شيئاً حين يصل كرسالة واردة */
    #[Test]
    public function an_otp_code_arriving_as_an_inbound_message_verifies_nothing(): void
    {
        $code = app(PhoneVerificationService::class)->issue($this->user->phone);

        $result = $this->challenges->consume('+967771234567', (string) $code);

        $this->assertSame(ChallengeResult::NoChallenge, $result);
        $this->assertFalse($this->user->fresh()->hasVerifiedPhone());
    }

    // ─── الدورة ───

    #[Test]
    public function a_matching_message_from_the_right_number_verifies_the_account(): void
    {
        $challenge = $this->challenges->issue($this->user);

        $result = $this->challenges->consume(
            '0771234567',
            $challenge['token'],
        );

        $this->assertSame(ChallengeResult::Verified, $result);
        $this->assertTrue($this->user->fresh()->hasVerifiedPhone());
    }

    /**
     * المرسِل هو الإثبات: رمزٌ صحيح من رقم آخر لا يوثّق شيئاً.
     *
     * وهذه الحالة واقعية لا نظرية: من ينسخ الرمز عن شاشة غيره.
     */
    #[Test]
    public function the_right_token_from_the_wrong_number_proves_nothing(): void
    {
        $challenge = $this->challenges->issue($this->user);

        $result = $this->challenges->consume('0779999999', $challenge['token']);

        $this->assertSame(ChallengeResult::NoChallenge, $result);
        $this->assertFalse($this->user->fresh()->hasVerifiedPhone());
    }

    #[Test]
    public function a_wrong_token_burns_an_attempt(): void
    {
        $this->challenges->issue($this->user);

        $this->assertSame(
            ChallengeResult::Invalid,
            $this->challenges->consume('0771234567', 'ZZZZZZ'),
        );

        $this->assertSame(1, PhoneChallenge::sole()->attempts);
    }

    #[Test]
    public function too_many_wrong_tokens_lock_the_challenge(): void
    {
        $challenge = $this->challenges->issue($this->user);

        for ($i = 0; $i < 5; $i++) {
            $this->challenges->consume('0771234567', 'ZZZZZZ');
        }

        // ولو كان الرمز صحيحاً — القفل على التحدّي لا على المحاولة
        $this->assertSame(
            ChallengeResult::Locked,
            $this->challenges->consume('0771234567', $challenge['token']),
        );
    }

    #[Test]
    public function a_token_works_only_once(): void
    {
        $challenge = $this->challenges->issue($this->user);

        $this->challenges->consume('0771234567', $challenge['token']);

        $this->assertSame(
            ChallengeResult::NoChallenge,
            $this->challenges->consume('0771234567', $challenge['token']),
        );
    }

    #[Test]
    public function issuing_a_new_challenge_kills_the_previous_one(): void
    {
        $first = $this->challenges->issue($this->user);
        $this->challenges->issue($this->user);

        $this->assertSame(
            ChallengeResult::Invalid,
            $this->challenges->consume('0771234567', $first['token']),
        );
    }

    #[Test]
    public function an_expired_challenge_is_refused(): void
    {
        $challenge = $this->challenges->issue($this->user);

        $this->travel($this->challenges->ttlMinutes() + 1)->minutes();

        $this->assertSame(
            ChallengeResult::NoChallenge,
            $this->challenges->consume('0771234567', $challenge['token']),
        );
    }

    /** الأبجدية بلا حروف ملتبسة — الرمز يُقرأ من شاشة ويُكتب بالإبهام */
    #[Test]
    public function the_token_avoids_ambiguous_characters(): void
    {
        for ($batch = 0; $batch < 4; $batch++) {
            // السفر بين الدفعات: حدّ الإصدار خمسة في عشر دقائق، وهو
            // حارسٌ مقصود — تجاوزه في الاختبار كان سيُخفيه
            $this->travel(11)->minutes();

            for ($i = 0; $i < 5; $i++) {
                $token = $this->challenges->issue($this->user)['token'];

                $this->assertMatchesRegularExpression('/^[A-HJ-KM-NP-Z2-9]{6}$/', $token);
                $this->assertDoesNotMatchRegularExpression('/[O0I1L]/', $token);
            }
        }
    }

    // ─── انتزاع الرمز من النصّ ───

    #[Test]
    public function the_prefix_is_required_before_a_token_is_extracted(): void
    {
        $this->assertSame('4F7K2M', $this->challenges->extractToken('SQ 4F7K2M'));
        $this->assertSame('4F7K2M', $this->challenges->extractToken('sq-4f7k2m'));
        $this->assertSame('4F7K2M', $this->challenges->extractToken('SQ 4F7K2M شكرا'));

        // بلا بادئة لا التقاط — وإلا التُقط رمز مصرفٍ وصل مصادفةً
        $this->assertNull($this->challenges->extractToken('4F7K2M'));
        $this->assertNull($this->challenges->extractToken('رمزك المصرفي 482910'));
    }

    // ─── نقطة النهاية ───

    #[Test]
    public function the_worker_uploads_an_inbound_message_and_the_account_is_verified(): void
    {
        $challenge = $this->challenges->issue($this->user);

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/inbound', [
                'messages' => [
                    ['from' => '0771234567', 'body' => 'SQ '.$challenge['token']],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('processed', 1)
            ->assertJsonPath('verified', 1);

        $this->assertTrue($this->user->fresh()->hasVerifiedPhone());
    }

    /** الردّ لا يقول أيّ رسالة طابقت — الهاتف لا يحتاج ربط رقم بحساب */
    #[Test]
    public function the_response_reveals_no_number_and_no_token(): void
    {
        $challenge = $this->challenges->issue($this->user);

        $response = $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/inbound', [
                'messages' => [['from' => '0771234567', 'body' => 'SQ '.$challenge['token']]],
            ])->assertOk();

        $this->assertStringNotContainsString('771234567', $response->getContent());
        $this->assertStringNotContainsString($challenge['token'], $response->getContent());
    }

    #[Test]
    public function the_inbound_endpoint_is_closed_without_the_worker_secret(): void
    {
        $this->postJson('/api/v1/sms/inbound', [
            'messages' => [['from' => '0771234567', 'body' => 'SQ ABCDEF']],
        ])->assertUnauthorized();
    }

    #[Test]
    public function inbound_is_refused_when_the_channel_is_off(): void
    {
        config(['sms.inbound.enabled' => false]);

        $this->withToken(self::TOKEN)
            ->postJson('/api/v1/sms/inbound', [
                'messages' => [['from' => '0771234567', 'body' => 'SQ ABCDEF']],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'inbound_disabled');
    }

    /** والرفع المكرَّر غير ضارّ: الثاني يجد التحدّي مُستهلَكاً */
    #[Test]
    public function uploading_the_same_message_twice_is_harmless(): void
    {
        $challenge = $this->challenges->issue($this->user);
        $payload = ['messages' => [['from' => '0771234567', 'body' => 'SQ '.$challenge['token']]]];

        $this->withToken(self::TOKEN)->postJson('/api/v1/sms/inbound', $payload)->assertJsonPath('verified', 1);
        $this->withToken(self::TOKEN)->postJson('/api/v1/sms/inbound', $payload)->assertJsonPath('verified', 0);

        $this->assertTrue($this->user->fresh()->hasVerifiedPhone());
    }

    // ─── الواجهات ───

    #[Test]
    public function the_challenge_page_shows_the_token_and_our_number(): void
    {
        $response = $this->actingAs($this->user)->get('/verify-by-sms')->assertOk();

        $token = PhoneChallenge::sole();

        $response->assertSee('0770000000');
        $response->assertSee('SQ ', false);
        $this->assertNotNull($token);
    }

    #[Test]
    public function the_verify_page_links_to_the_reverse_channel(): void
    {
        $this->actingAs($this->user)
            ->get('/verify-phone')
            ->assertOk()
            ->assertSee(route('sms.challenge'));
    }

    #[Test]
    public function the_app_gets_the_same_challenge_over_the_api(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/sms/challenge')
            ->assertOk()
            ->assertJsonStructure(['text', 'send_to', 'expires_at', 'sms_uri']);
    }

    /** والتحدّي يُصدَر لصاحب الرمز لا لرقمٍ في الجسد */
    #[Test]
    public function the_api_challenge_needs_a_personal_token(): void
    {
        $this->postJson('/api/v1/sms/challenge')->assertUnauthorized();

        // وسرّ العامل لا يصلح هنا: لو صلح لأصدر تحدّيات لأي حساب
        $this->withToken(self::TOKEN)->postJson('/api/v1/sms/challenge')->assertUnauthorized();
    }
}
