<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\DeliverSms;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * خيار الرسالة النصية يتبع SMS_ENABLED.
 *
 * `Channel::isImplemented` كانت تُعيد true دائماً فيظهر عمود SMS لكل
 * المستخدمين ولو كانت الوحدة مطفأة، فيؤشّرون خياراً لا يصل بعده شيء.
 */
class SmsChannelVisibilityTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function the_sms_option_is_hidden_when_sms_is_disabled(): void
    {
        config(['sms.enabled' => false]);

        $this->assertFalse(Channel::Sms->isImplemented());

        $this->actingAs($this->user)
            ->get(route('account.notifications.preferences'))
            ->assertOk()
            ->assertDontSee(Channel::Sms->label())
            ->assertDontSee('name="preferences['.NotificationType::NewMessage->value.'][sms]"', false)
            ->assertSee(Channel::Push->label());
    }

    #[Test]
    public function the_sms_option_is_visible_when_sms_is_enabled(): void
    {
        config(['sms.enabled' => true]);

        $this->assertTrue(Channel::Sms->isImplemented());

        $this->actingAs($this->user)
            ->get(route('account.notifications.preferences'))
            ->assertOk()
            ->assertSee(Channel::Sms->label())
            ->assertSee('name="preferences['.NotificationType::NewMessage->value.'][sms]"', false);
    }

    #[Test]
    public function the_other_channels_do_not_depend_on_the_sms_switch(): void
    {
        foreach ([true, false] as $enabled) {
            config(['sms.enabled' => $enabled]);

            $this->assertTrue(Channel::InApp->isImplemented());
            $this->assertTrue(Channel::Push->isImplemented());
            $this->assertTrue(Channel::Email->isImplemented());
        }
    }

    #[Test]
    public function a_saved_sms_preference_survives_a_save_while_the_option_is_hidden(): void
    {
        config(['sms.enabled' => true]);
        app(PreferenceResolver::class)->save($this->user, NotificationType::NewMessage, ['sms' => true]);

        config(['sms.enabled' => false]);

        // المستخدم يحفظ من الشاشة والعمود مخفي: sms لا يصل في الطلب
        $this->actingAs($this->user)
            ->put(route('account.notifications.preferences.update'), [
                'preferences' => [NotificationType::NewMessage->value => ['in_app' => '1']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->user->id,
            'notification_type' => NotificationType::NewMessage->value,
            'sms' => true,
            'push' => false,
        ]);

        // وحين تُفعَّل الوحدة لاحقاً يعود اختياره القديم
        config(['sms.enabled' => true]);
        $this->assertTrue(
            app(PreferenceResolver::class)->allows($this->user, NotificationType::NewMessage, Channel::Sms)
        );
    }

    /** على مستوى الخدمة مباشرة: حفظ قنوات أخرى لا يمسّ sms المحفوظ، مفعَّلةً كانت أم لا */
    #[Test]
    public function saving_other_channels_never_wipes_a_saved_sms_choice(): void
    {
        $resolver = app(PreferenceResolver::class);

        config(['sms.enabled' => true]);
        $resolver->save($this->user, NotificationType::NewMessage, ['sms' => true]);

        foreach ([false, true] as $enabled) {
            config(['sms.enabled' => $enabled]);

            $resolver->save($this->user, NotificationType::NewMessage, ['push' => false, 'email' => true]);

            $this->assertDatabaseHas('notification_preferences', [
                'user_id' => $this->user->id,
                'notification_type' => NotificationType::NewMessage->value,
                'sms' => true,
                'push' => false,
                'email' => true,
            ]);
        }
    }

    #[Test]
    public function a_saved_sms_preference_does_not_make_the_sender_try_a_disabled_channel(): void
    {
        Queue::fake();

        config(['sms.enabled' => true]);
        app(PreferenceResolver::class)->save($this->user, NotificationType::NewMessage, ['sms' => true]);
        $this->user->markPhoneAsVerified();

        config(['sms.enabled' => false]);

        $this->assertFalse(
            app(PreferenceResolver::class)->allows($this->user, NotificationType::NewMessage, Channel::Sms)
        );

        app(Notifier::class)->send(
            recipient: $this->user,
            type: NotificationType::NewMessage,
            title: 'رسالة جديدة',
            data: ['entity_type' => 'conversation', 'entity_id' => 1],
        );

        Queue::assertNotPushed(DeliverSms::class);
    }
}
