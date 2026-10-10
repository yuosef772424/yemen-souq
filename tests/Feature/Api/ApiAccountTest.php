<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * الحساب من التطبيق.
 *
 * ما يُختبر هنا ليس تكراراً لاختبار الويب: الطلبات مشتركة بين المسارين
 * (القرار لا يُنسخ)، والمختلف هو **ما بعد التحقق** — إبطال رموز الأجهزة
 * الأخرى، وعودة رمز التوثيق في الجسد بدل رسالة جلسة، ومحو كل الرموز
 * عند الإغلاق.
 */
class ApiAccountTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->user = User::factory()->create([
            'phone' => '+967771234567',
            'password' => Hash::make('password1234'),
        ]);

        $this->user->markPhoneAsVerified();

        $this->token = $this->user->createToken('هاتفي')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    // ─── الحراسة ───

    #[Test]
    public function every_account_endpoint_needs_a_token(): void
    {
        $this->putJson(route('api.v1.me.profile'))->assertUnauthorized();
        $this->putJson(route('api.v1.me.password'))->assertUnauthorized();
        $this->putJson(route('api.v1.me.phone'))->assertUnauthorized();
        $this->getJson(route('api.v1.me.privacy'))->assertUnauthorized();
        $this->getJson(route('api.v1.me.notification-preferences'))->assertUnauthorized();
        $this->deleteJson(route('api.v1.me.destroy'))->assertUnauthorized();
    }

    // ─── البيانات ───

    #[Test]
    public function the_profile_is_updated_and_returned(): void
    {
        $this->putJson(route('api.v1.me.profile'), [
            'name' => 'اسم جديد',
            'email' => $this->user->email,
            'display_name' => 'معرض الأمانة',
            'bio' => 'نبيع ونشتري.',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.name', 'معرض الأمانة');

        $this->assertSame('اسم جديد', $this->user->fresh()->name);
        $this->assertSame('نبيع ونشتري.', $this->user->fresh()->profile->bio);
    }

    #[Test]
    public function the_profile_rules_are_the_same_as_the_web(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->putJson(route('api.v1.me.profile'), [
            'name' => 'اسم',
            'email' => 'taken@example.test',
            'display_name' => 'معرض',
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ─── كلمة المرور ───

    /**
     * أهمّ فرق عن الويب: الأجهزة الأخرى تخرج، والجهاز الحالي يبقى.
     *
     * من غيّر كلمته لأنه يشكّ في جهازٍ آخر يجب أن يخرج ذلك الجهاز فعلاً —
     * وإخراجه هو أيضاً يجعله يظنّ أن العملية فشلت.
     */
    #[Test]
    public function changing_the_password_revokes_other_devices_only(): void
    {
        $otherDevice = $this->user->createToken('جهاز ثانٍ')->plainTextToken;

        $this->putJson(route('api.v1.me.password'), [
            'current_password' => 'password1234',
            'password' => 'new-password-9876',
            'password_confirmation' => 'new-password-9876',
        ], $this->auth())->assertOk();

        $this->assertTrue(Hash::check('new-password-9876', $this->user->fresh()->password));

        // الفحص على الجدول لا بطلبٍ ثانٍ: حارس المصادقة يبقى محلولاً
        // داخل الطلب الواحد في الاختبار، فطلبٌ تالٍ بنفس التطبيق يمرّ
        // برمزٍ مُبطَل — وهو نفس ما تفعله اختبارات الخروج القائمة
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'هاتفي']);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'جهاز ثانٍ']);
    }

    #[Test]
    public function a_wrong_current_password_changes_nothing(): void
    {
        $this->putJson(route('api.v1.me.password'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-9876',
            'password_confirmation' => 'new-password-9876',
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('password1234', $this->user->fresh()->password));
    }

    // ─── الرقم ───

    #[Test]
    public function a_new_phone_starts_unverified_and_carries_its_code(): void
    {
        $this->putJson(route('api.v1.me.phone'), [
            'phone' => '0773333333',
            'current_password' => 'password1234',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.phone_verified', false)
            ->assertJsonPath('data.can_publish', false);

        $this->assertSame('+967773333333', $this->user->fresh()->phone);
        $this->assertSame(1, PhoneVerification::where('phone', '+967773333333')->count());
    }

    // ─── تفضيلات الإشعارات ───

    #[Test]
    public function the_preferences_read_carries_its_labels(): void
    {
        $this->getJson(route('api.v1.me.notification-preferences'), $this->auth())
            ->assertOk()
            ->assertJsonCount(count(NotificationType::cases()), 'data')
            ->assertJsonPath('data.0.type', NotificationType::NewMessage->value)
            ->assertJsonPath('data.0.label', NotificationType::NewMessage->label())
            ->assertJsonPath('data.0.channels.push', true)
            // البريد مطفأ افتراضياً لا غائب. والرسالة النصية غائبة كلياً
            // ما دامت وحدة Sms مطفأة (SMS_ENABLED=false في phpunit.xml)
            ->assertJsonMissingPath('data.0.channels.sms')
            ->assertJsonPath('data.0.channels.email', false);
    }

    #[Test]
    public function turning_push_off_through_the_api_stops_it(): void
    {
        $this->putJson(route('api.v1.me.notification-preferences'), [
            'preferences' => [
                NotificationType::NewMessage->value => ['in_app' => true, 'push' => false],
            ],
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.channels.push', false);

        $this->assertFalse(
            app(PreferenceResolver::class)->allows($this->user, NotificationType::NewMessage, Channel::Push)
        );
    }

    /** والجسد الفارغ يُرفض بدل أن يُطفئ كل شيء بلا قصد */
    #[Test]
    public function an_empty_body_is_refused_rather_than_read_as_silence(): void
    {
        $this->putJson(route('api.v1.me.notification-preferences'), [], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['preferences']);

        $this->assertTrue(
            app(PreferenceResolver::class)->allows($this->user, NotificationType::NewMessage, Channel::Push)
        );
    }

    // ─── الخصوصية ───

    #[Test]
    public function privacy_defaults_to_allowed_without_a_row(): void
    {
        $this->getJson(route('api.v1.me.privacy'), $this->auth())
            ->assertOk()
            ->assertJsonPath('data.allow_analytics', true)
            ->assertJsonPath('data.allow_personalization', true);
    }

    #[Test]
    public function opting_out_is_saved_and_wipes_the_behavioural_profile(): void
    {
        DB::table('user_category_affinity')->insert([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'score' => 9.5,
            'last_event_at' => now(),
            'computed_at' => now(),
        ]);

        $this->putJson(route('api.v1.me.privacy'), [
            'allow_analytics' => false,
            'allow_personalization' => false,
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.allow_analytics', false);

        $this->assertSame(0, DB::table('user_category_affinity')->where('user_id', $this->user->id)->count());
    }

    // ─── الإغلاق ───

    #[Test]
    public function closing_the_account_kills_every_token(): void
    {
        $this->user->createToken('جهاز ثانٍ');
        $listing = $this->listingFor($this->user);

        $this->deleteJson(route('api.v1.me.destroy'), [
            'current_password' => 'password1234',
        ], $this->auth())->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->assertNull(User::find($this->user->id));
        $this->assertNull(User::withTrashed()->find($this->user->id)->phone);

        // وإعلاناته تغادر السوق معه — UserObserver في وحدة Catalog
        $this->assertNull(Listing::find($listing->id));
        $this->assertNotNull(Listing::withTrashed()->find($listing->id)->deleted_at);
    }

    #[Test]
    public function closing_needs_the_password(): void
    {
        $this->deleteJson(route('api.v1.me.destroy'), [
            'current_password' => 'wrong-password',
        ], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertNotNull(User::find($this->user->id));
    }
}
