<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Models\PhoneVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * استعادة كلمة المرور برمز الجوال.
 *
 * أثقل ما يُختبر هنا ليس المسار الناجح بل ما حوله: عدم كشف الحسابات
 * المسجَّلة، وسقف المحاولات، وإبطال الجلسات المحفوظة.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'صاحب الحساب',
            'phone' => '+967771234567',
            'email' => 'owner@example.test',
            'password' => Hash::make('old-password-123'),
        ]);
    }

    private function ask(string $phone = '0771234567')
    {
        return $this->post(route('password.request'), ['phone' => $phone]);
    }

    /** الرمز الصريح من سائق العرض — لا يُقرأ من القاعدة لأنه مجزّأ فيها */
    private function issuedCode(): string
    {
        return session('demo_code');
    }

    // ═══ الخطوة الأولى ═══

    #[Test]
    public function it_issues_a_code_for_a_registered_phone(): void
    {
        $this->ask()->assertRedirect(route('password.reset'));

        $this->assertDatabaseCount('phone_verifications', 1);
        $this->assertNotNull($this->issuedCode());
    }

    /**
     * الحارس الأهمّ: لا فرق ظاهر بين رقم مسجَّل وغير مسجَّل.
     *
     * من يجرّب ألف رقم على استجابةٍ تفرّق بينهما يحصل على قائمة
     * مستخدمي المنصة — وهي في سوق إعلانات قائمةُ من يملك ما يُباع.
     */
    #[Test]
    public function an_unregistered_phone_gets_the_same_response(): void
    {
        $registered = $this->ask('0771234567');
        $unknown = $this->post(route('password.request'), ['phone' => '0777654321']);

        $this->assertSame($registered->getStatusCode(), $unknown->getStatusCode());
        $unknown->assertRedirect(route('password.reset'));
        $unknown->assertSessionHas('status', $registered->getSession()->get('status'));
    }

    /** ولا يُصدَر رمز لرقم غير مسجَّل — لا صفّ ولا رسالة ولا كلفة */
    #[Test]
    public function no_code_is_issued_for_an_unregistered_phone(): void
    {
        $this->post(route('password.request'), ['phone' => '0777654321']);

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    #[Test]
    public function a_malformed_phone_is_rejected(): void
    {
        $this->post(route('password.request'), ['phone' => '12345'])
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    /**
     * الحدّ يشمل الأرقام غير المسجَّلة.
     *
     * حدّ الخدمة يُحسب على الرموز الصادرة، ومسار الرقم غير المسجَّل لا
     * يُصدر رمزاً — فبلا حدٍّ في المتحكّم يبقى عدّ الحسابات بلا سقف.
     */
    #[Test]
    public function repeated_requests_for_an_unregistered_phone_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.request'), ['phone' => '0777654321']);
        }

        $this->post(route('password.request'), ['phone' => '0777654321'])
            ->assertSessionHasErrors('phone');
    }

    // ═══ الخطوة الثانية ═══

    #[Test]
    public function a_valid_code_changes_the_password(): void
    {
        $this->ask();
        $code = $this->issuedCode();

        $this->post(route('password.reset'), [
            'code' => $code,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('brand-new-pass-9', $this->user->fresh()->password));
    }

    #[Test]
    public function the_new_password_actually_logs_in(): void
    {
        $this->ask();

        $this->post(route('password.reset'), [
            'code' => $this->issuedCode(),
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ]);

        $this->post(route('login'), [
            'identifier' => '0771234567',
            'password' => 'brand-new-pass-9',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function a_wrong_code_changes_nothing(): void
    {
        $this->ask();

        $this->post(route('password.reset'), [
            'code' => '000000',
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('old-password-123', $this->user->fresh()->password));
    }

    /** الرمز يُستهلك مرة — إعادة استعماله لا تعيد فتح الباب */
    #[Test]
    public function a_used_code_cannot_be_used_twice(): void
    {
        $this->ask();
        $code = $this->issuedCode();

        $this->post(route('password.reset'), [
            'code' => $code,
            'password' => 'first-new-pass-9',
            'password_confirmation' => 'first-new-pass-9',
        ]);

        // الجلسة نُظّفت بعد النجاح، فالمحاولة الثانية تعود إلى البداية
        $this->post(route('password.reset'), [
            'code' => $code,
            'password' => 'second-new-pass-9',
            'password_confirmation' => 'second-new-pass-9',
        ])->assertRedirect(route('password.request'));

        $this->assertTrue(Hash::check('first-new-pass-9', $this->user->fresh()->password));
    }

    #[Test]
    public function an_expired_code_is_refused(): void
    {
        $this->ask();
        $code = $this->issuedCode();

        PhoneVerification::query()->update(['expires_at' => now()->subMinute()]);

        $this->post(route('password.reset'), [
            'code' => $code,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertSessionHasErrors('code');
    }

    #[Test]
    public function the_password_rules_match_registration(): void
    {
        $this->ask();

        $this->post(route('password.reset'), [
            'code' => $this->issuedCode(),
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
    }

    #[Test]
    public function the_confirmation_must_match(): void
    {
        $this->ask();

        $this->post(route('password.reset'), [
            'code' => $this->issuedCode(),
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'something-else-9',
        ])->assertSessionHasErrors('password');
    }

    /** بلا رقم في الجلسة لا صفحة — والعودة إلى البداية لا 500 */
    #[Test]
    public function reaching_the_second_step_directly_sends_you_back(): void
    {
        $this->get(route('password.reset'))->assertRedirect(route('password.request'));

        $this->post(route('password.reset'), [
            'code' => '123456',
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertRedirect(route('password.request'));
    }

    /**
     * الرقم يبقى في الجلسة بعد خطأ.
     *
     * flash يعيش طلباً واحداً، وبلا keep يسقط المستخدم إلى البداية بعد
     * أول خطأ مطبعي في الرمز — ويضطر إلى طلب رمز جديد يستهلك حدّ الإرسال.
     */
    #[Test]
    public function a_typo_in_the_code_keeps_you_on_the_second_step(): void
    {
        $this->ask();

        $this->post(route('password.reset'), [
            'code' => '000000',
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ]);

        $this->get(route('password.reset'))->assertOk();
    }

    // ═══ آثار جانبية مقصودة ═══

    /**
     * كل كوكي «أبقني مسجَّلاً» يبطل.
     *
     * من استعاد كلمته الغالب أنه فقد جهازه أو خشي اختراقاً — وكلمةٌ
     * جديدة مع كوكي قديم يعمل ليست استعادة.
     */
    #[Test]
    public function every_remembered_session_is_invalidated(): void
    {
        $this->user->forceFill(['remember_token' => 'old-token-value'])->save();

        $this->ask();

        $this->post(route('password.reset'), [
            'code' => $this->issuedCode(),
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ]);

        $this->assertNotSame('old-token-value', $this->user->fresh()->remember_token);
    }

    /** استلام الرمز إثباتُ ملكيةِ الرقم — وهو نفس دليل التوثيق */
    #[Test]
    public function completing_the_reset_counts_as_verifying_the_phone(): void
    {
        $this->assertNull($this->user->phone_verified_at);

        $this->ask();

        $this->post(route('password.reset'), [
            'code' => $this->issuedCode(),
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ]);

        $this->assertNotNull($this->user->fresh()->phone_verified_at);
    }

    #[Test]
    public function a_logged_in_user_has_no_business_here(): void
    {
        $this->actingAs($this->user)
            ->get(route('password.request'))
            ->assertRedirect();
    }

    #[Test]
    public function the_login_page_offers_the_way_out(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.request'));
    }
}
