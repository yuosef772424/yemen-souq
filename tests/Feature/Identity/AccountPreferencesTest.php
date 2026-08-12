<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Analytics\Models\TrackingPreference;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * الشاشتان اللتان كانتا آلةً بلا مقبض.
 *
 * `PreferenceResolver` يقرأ `notification_preferences` في كل إرسال،
 * و`EventRecorder::allowed` يقرأ `user_tracking_preferences` في كل حدث —
 * وكلا الجدولين لم يكن له كاتب. أي أن المستخدم كان يملك تفضيلات لا
 * يستطيع تغييرها، ووعداً بالانسحاب لا يستطيع استعماله.
 *
 * ولذلك الاختبار لا يقف عند «حُفظ الصفّ»: يفحص أن الحفظ **غيّر السلوك**.
 */
class AccountPreferencesTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->user = User::factory()->create();
    }

    // ─── الإشعارات ───

    #[Test]
    public function the_preferences_screen_is_behind_a_login(): void
    {
        $this->get(route('account.notifications.preferences'))->assertRedirect(route('login'));
        $this->get(route('account.privacy'))->assertRedirect(route('login'));
    }

    #[Test]
    public function turning_a_notification_off_actually_stops_it(): void
    {
        $resolver = app(PreferenceResolver::class);

        $this->assertTrue($resolver->allows($this->user, NotificationType::NewMessage, Channel::Push));

        $this->actingAs($this->user)
            ->put(route('account.notifications.preferences.update'), [
                'preferences' => [
                    NotificationType::NewMessage->value => ['in_app' => '1'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $fresh = app(PreferenceResolver::class);

        $this->assertTrue($fresh->allows($this->user, NotificationType::NewMessage, Channel::InApp));
        $this->assertFalse($fresh->allows($this->user, NotificationType::NewMessage, Channel::Push));
    }

    /**
     * الفخّ الذي دفع إلى المرور على التعداد لا على المُرسَل: مربّع غير
     * مؤشَّر لا يُرسَل أصلاً، فالقراءة من المُرسَل وحده تجعل الإطفاء
     * لا يُحفظ أبداً.
     */
    #[Test]
    public function an_empty_form_turns_everything_off_rather_than_changing_nothing(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.notifications.preferences.update'), [])
            ->assertSessionHasNoErrors();

        $resolver = app(PreferenceResolver::class);

        $this->assertFalse($resolver->allows($this->user, NotificationType::NewMessage, Channel::InApp));
        $this->assertFalse($resolver->allows($this->user, NotificationType::NewQuestion, Channel::Push));
    }

    /** والإجباري يبقى — قرار الإشراف يصل داخل الموقع مهما أُرسل */
    #[Test]
    public function a_mandatory_notification_survives_an_empty_form(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.notifications.preferences.update'), []);

        $this->assertTrue(
            app(PreferenceResolver::class)->allows($this->user, NotificationType::ListingRejected, Channel::InApp)
        );

        // ولا يكذب الصفّ على من يقرأ الجدول مباشرةً
        $this->assertSame(1, (int) DB::table('notification_preferences')
            ->where('user_id', $this->user->id)
            ->where('notification_type', NotificationType::ListingRejected->value)
            ->value('in_app'));
    }

    // ─── الخصوصية ───

    /**
     * والفحص على السلوك لا على الصفّ: صفحة بحث واحدة تولّد أحداثاً
     * فعلاً، فغيابها بعد الانسحاب هو الدليل الوحيد المقنع.
     */
    #[Test]
    public function opting_out_stops_the_recorder(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.privacy.update'), ['allow_personalization' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(TrackingPreference::findOrFail($this->user->id)->allow_analytics);

        $this->actingAs($this->user)->get(route('search', ['q' => 'سيارة']));

        $this->assertSame(0, DB::table('user_events')->where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function the_default_without_a_row_is_still_recorded(): void
    {
        $this->assertNull($this->user->trackingPreferences);

        $this->actingAs($this->user)->get(route('search', ['q' => 'سيارة']));

        $this->assertGreaterThan(0, DB::table('user_events')->where('user_id', $this->user->id)->count());
    }

    /** وإيقاف التخصيص يمحو ما بُني فوراً لا في التشغيل الليلي */
    #[Test]
    public function turning_personalization_off_wipes_the_profile_built_from_behaviour(): void
    {
        DB::table('user_category_affinity')->insert([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'score' => 12.5,
            'last_event_at' => now(),
            'computed_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->put(route('account.privacy.update'), ['allow_analytics' => '1']);

        $this->assertSame(0, DB::table('user_category_affinity')->where('user_id', $this->user->id)->count());
    }
}
