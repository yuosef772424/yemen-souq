<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Identity\Support\LoginThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * التسجيل والدخول والخروج بالرمز.
 */
class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        LoginThrottle::clear('0771234567');
        LoginThrottle::clear('+967771234567');

        parent::tearDown();
    }

    #[Test]
    public function it_registers_an_account_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'أحمد الشرعبي',
            'phone' => '0771234567',
            'email' => 'ahmed@example.test',
            'password' => 'strong-password-1',
            'password_confirmation' => 'strong-password-1',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name', 'phone'], 'token', 'expires_at'])
            ->assertJsonPath('data.phone_verified', false)
            ->assertJsonPath('data.can_publish', false);

        // الرقم يُطبَّع قبل التحقق — القيد الفريد على الصيغة الموحّدة
        $this->assertDatabaseHas('users', ['phone' => '+967771234567']);
        // الملف الشخصي في نفس المعاملة — بلاه تنكسر كل قراءة لـdisplayName
        $this->assertDatabaseHas('user_profiles', ['display_name' => 'أحمد الشرعبي']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /**
     * رمز التوثيق يُصدَر مع التسجيل — لا ينتظر طلباً ثانياً.
     */
    #[Test]
    public function registering_issues_a_phone_verification_code(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'أحمد الشرعبي',
            'phone' => '0771234567',
            'email' => 'ahmed@example.test',
            'password' => 'strong-password-1',
            'password_confirmation' => 'strong-password-1',
        ])->assertCreated();

        $this->assertDatabaseCount('phone_verifications', 1);
    }

    #[Test]
    public function it_logs_in_with_a_yemeni_phone_in_any_format(): void
    {
        User::factory()->create([
            'phone' => '+967771234567',
            'password' => Hash::make('strong-password-1'),
        ]);

        // الصيغة المحلية تصل إلى نفس الحساب — YemeniPhone يطبّعها
        $this->postJson('/api/v1/auth/login', [
            'identifier' => '0771234567',
            'password' => 'strong-password-1',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    #[Test]
    public function it_logs_in_with_an_email(): void
    {
        User::factory()->create([
            'email' => 'ahmed@example.test',
            'password' => Hash::make('strong-password-1'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'ahmed@example.test',
            'password' => 'strong-password-1',
        ])->assertOk();
    }

    #[Test]
    public function wrong_credentials_are_rejected_without_a_token(): void
    {
        User::factory()->create([
            'email' => 'ahmed@example.test',
            'password' => Hash::make('strong-password-1'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'ahmed@example.test',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonValidationErrors('identifier');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * الحساب الموقوف يُرفَض برسالة صريحة لا بـ«بيانات غير صحيحة».
     *
     * الكذب هنا يدفع صاحب الحساب إلى إعادة تعيين كلمة مروره مرة بعد
     * مرة بلا فائدة.
     */
    #[Test]
    public function a_banned_account_is_told_so(): void
    {
        $user = User::factory()->create([
            'email' => 'ahmed@example.test',
            'password' => Hash::make('strong-password-1'),
        ]);
        $user->forceFill(['status' => 'banned'])->save();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'ahmed@example.test',
            'password' => 'strong-password-1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.identifier.0', 'هذا الحساب محظور.');
    }

    /**
     * أهم اختبار في الملف: ميزانية المحاولات مشتركة بين الويب والـAPI.
     *
     * لو كان لكلٍّ مفتاحه لضاعف من يخمّن كلمة المرور محاولاته بالتنقّل
     * بين القناتين — أي أن إضافة الـAPI تُضعف حماية الويب بلا أن يظهر
     * ذلك في أي اختبار لكلٍّ منهما وحده.
     */
    #[Test]
    public function the_login_budget_is_shared_with_the_web(): void
    {
        User::factory()->create([
            'email' => 'ahmed@example.test',
            'password' => Hash::make('strong-password-1'),
        ]);

        // خمس محاولات فاشلة عبر الويب تستنفد الميزانية
        for ($i = 0; $i < LoginThrottle::MAX_ATTEMPTS; $i++) {
            $this->post('/login', [
                'identifier' => 'ahmed@example.test',
                'password' => 'wrong',
            ]);
        }

        // والـAPI يجد الباب مقفلاً — حتى بكلمة المرور الصحيحة
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'ahmed@example.test',
            'password' => 'strong-password-1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.identifier.0', 'محاولات كثيرة. انتظر دقيقة ثم أعد المحاولة.');

        LoginThrottle::clear('ahmed@example.test');
    }

    #[Test]
    public function me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('strong-password-1')]);

        $first = $user->createToken('phone-a')->plainTextToken;
        $user->createToken('phone-b');

        $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'phone-b']);
    }

    #[Test]
    public function logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('phone-a')->plainTextToken;
        $user->createToken('phone-b');

        $this->withToken($token)->postJson('/api/v1/auth/logout-all')
            ->assertOk()
            ->assertJsonPath('revoked', 2);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * الرمز المنتهي لا يوثّق — وهو ما يجعل عمر الرمز سياسةً لا تعليقاً.
     */
    #[Test]
    public function an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('phone', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /**
     * السقف يحذف الأقدم بدل أن يرفض الجديد: من يعيد تنصيب التطبيق
     * كثيراً يجب ألّا يُمنع من الدخول إلى حسابه.
     */
    #[Test]
    public function issuing_beyond_the_ceiling_drops_the_oldest_token(): void
    {
        config(['api.tokens.max_per_user' => 2]);

        $user = User::factory()->create([
            'email' => 'ahmed@example.test',
            'password' => Hash::make('strong-password-1'),
        ]);

        foreach (['a', 'b', 'c'] as $name) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => 'ahmed@example.test',
                'password' => 'strong-password-1',
                'device_name' => $name,
            ])->assertOk();
        }

        $this->assertSame(2, $user->tokens()->count());
        $this->assertSame(['b', 'c'], $user->tokens()->orderBy('id')->pluck('name')->all());
    }

    // ─── استعادة كلمة المرور ───

    /**
     * الاستجابة واحدة للرقم المسجَّل وغيره.
     *
     * مسارٌ يقول «هذا الرقم غير مسجَّل» يسلّم لمن يجرّب ألف رقم قائمةَ
     * مستخدمي المنصة.
     */
    #[Test]
    public function forgot_password_answers_the_same_for_unknown_numbers(): void
    {
        User::factory()->create(['phone' => '+967771234567']);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0771234567']);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0779999999']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame(
            $known->json('message'),
            $unknown->json('message'),
        );

        // ولا رمز يُصدَر لغير المسجَّل
        $this->assertDatabaseCount('phone_verifications', 1);
    }

    #[Test]
    public function resetting_the_password_revokes_old_tokens_and_issues_a_new_one(): void
    {
        $user = User::factory()->create([
            'phone' => '+967771234567',
            'password' => Hash::make('old-password-1'),
        ]);
        $user->createToken('stolen-device');

        // سائق العرض (الافتراضي في التطوير والاختبار) يُعيد الرمز في
        // الاستجابة — في الإنتاج يوصّله المزوّد ويعود الحقل فارغاً
        $code = $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0771234567'])
            ->assertOk()
            ->json('verification_code');

        $this->assertNotNull($code);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '0771234567',
            'code' => $code,
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertOk()->assertJsonStructure(['token']);

        // الرمز القديم بطل، والجديد وحده قائم
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'stolen-device']);
        $this->assertTrue(Hash::check('brand-new-password-1', $user->fresh()->password));

        // والرقم صار موثَّقاً: الدليل هو استلام الرمز عليه
        $this->assertNotNull($user->fresh()->phone_verified_at);

        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    #[Test]
    public function a_wrong_reset_code_changes_nothing(): void
    {
        $user = User::factory()->create([
            'phone' => '+967771234567',
            'password' => Hash::make('old-password-1'),
        ]);

        $this->postJson('/api/v1/auth/password/forgot', ['phone' => '0771234567'])->assertOk();

        $this->postJson('/api/v1/auth/password/reset', [
            'phone' => '0771234567',
            'code' => '000000',
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }
}
