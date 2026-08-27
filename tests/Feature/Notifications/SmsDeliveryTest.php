<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Contracts\SmsSender;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\DeliverSms;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الرسالة النصية: من التفضيل إلى الطابور — بلا وحدة Sms مثبَّتة هنا.
 *
 * الاختبارات لا تفعّل وحدة Sms (نفس افتراض الإقلاع العادي): DeliverSms
 * يُستدعى بسائقٍ مزيَّف مباشرةً، تماماً كما يفعل PushDeliveryTest مع
 * LogPushSender — القناة الحقيقية محروسة في SmsModuleIsRemovableTest.
 */
class SmsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'صاحب الرقم',
            'phone' => '+967771234567',
            'email' => 'sms-user@example.test',
            'password' => Hash::make('password1234'),
        ]);
    }

    private function allowSms(): void
    {
        app(PreferenceResolver::class)->save($this->user, NotificationType::NewMessage, ['sms' => true]);
    }

    private function notify(): ?AppNotification
    {
        return app(Notifier::class)->send(
            recipient: $this->user,
            type: NotificationType::NewMessage,
            title: 'رسالة جديدة',
            body: 'بخصوص: سيارة',
            data: ['entity_type' => 'conversation', 'entity_id' => 1],
        );
    }

    #[Test]
    public function a_verified_phone_with_the_preference_on_gets_the_sms_queued(): void
    {
        Queue::fake();

        $this->user->markPhoneAsVerified();
        $this->allowSms();

        $this->notify();

        Queue::assertPushed(DeliverSms::class);
    }

    /** التفضيل مطفأ افتراضياً — لا رسالة بلا طلب صريح */
    #[Test]
    public function the_preference_off_by_default_means_no_sms(): void
    {
        Queue::fake();

        $this->user->markPhoneAsVerified();

        $this->notify();

        Queue::assertNotPushed(DeliverSms::class);
    }

    /**
     * رقمٌ غير موثَّق لا يُرسَل إليه — قد يكون في منتصف تغييرٍ لم يُثبَت بعد.
     *
     * انظر AccountController::updatePhone: تغيير الرقم يصفّر phone_verified_at
     * فوراً، فيبقى العمود يحمل رقماً قبل أن يُثبَت صاحبه.
     */
    #[Test]
    public function an_unverified_phone_does_not_get_the_sms(): void
    {
        Queue::fake();

        $this->allowSms();

        $this->notify();

        Queue::assertNotPushed(DeliverSms::class);
    }

    /**
     * الطيّ لا يُطلق رسالة ثانية — أهمّ حارس في هذا الملف.
     *
     * محادثة فيها عشر رسائل تطوي على صفّ واحد، ولو أُطلقت SMS مع كل طيّة
     * لكلّف حدثٌ واحدٌ يراه المستخدم صفاً واحداً عشر رسائل نصية حقيقية.
     */
    #[Test]
    public function a_collapsed_event_does_not_queue_a_second_sms(): void
    {
        Queue::fake();

        $this->user->markPhoneAsVerified();
        $this->allowSms();

        $this->notify();
        $this->notify();
        $this->notify();

        Queue::assertPushed(DeliverSms::class, 1);
    }

    /** التسليم يرفع channel إلى sms ويختم sent_at */
    #[Test]
    public function a_delivered_sms_is_stamped_on_the_row(): void
    {
        Queue::fake();

        $this->user->markPhoneAsVerified();
        $this->allowSms();

        $notification = $this->notify();

        (new DeliverSms($notification->id, $this->user->phone))->handle($this->fakeSender());

        $notification->refresh();

        $this->assertSame(Channel::Sms, $notification->channel);
        $this->assertNotNull($notification->sent_at);
    }

    /**
     * لا سائق مربوط — وحدة Sms محذوفة أو مُطفأة — والمهمّة تخرج بهدوء.
     *
     * هذا هو الافتراض الفعلي في بيئة الاختبار: SMS_ENABLED مطفأ، فلا رابط
     * لـSmsSender في الحاوية إطلاقاً.
     */
    #[Test]
    public function with_no_sender_bound_the_job_is_a_silent_no_op(): void
    {
        $this->assertFalse(app()->bound(SmsSender::class));

        Queue::fake();

        $this->user->markPhoneAsVerified();
        $this->allowSms();

        $notification = $this->notify();

        (new DeliverSms($notification->id, $this->user->phone))->handle();

        $this->assertNull($notification->refresh()->sent_at);
    }

    /** سقوط المزوّد لا يُفشل المهمّة ولا يختم التسليم */
    #[Test]
    public function a_failing_sender_does_not_throw(): void
    {
        Queue::fake();

        $this->user->markPhoneAsVerified();
        $this->allowSms();

        $notification = $this->notify();

        (new DeliverSms($notification->id, $this->user->phone))->handle($this->throwingSender());

        $this->assertNull($notification->refresh()->sent_at);
    }

    /** إشعار حُذف بين الجدولة والتنفيذ — الخروج بهدوء لا بـModelNotFound */
    #[Test]
    public function a_vanished_notification_is_skipped(): void
    {
        Queue::fake();

        $this->user->markPhoneAsVerified();
        $this->allowSms();

        $notification = $this->notify();
        $id = $notification->id;
        $notification->delete();

        (new DeliverSms($id, $this->user->phone))->handle($this->fakeSender());

        $this->assertDatabaseCount('app_notifications', 0);
    }

    private function fakeSender(): SmsSender
    {
        return new class implements SmsSender
        {
            public function send(string $phone, string $body): void {}
        };
    }

    private function throwingSender(): SmsSender
    {
        return new class implements SmsSender
        {
            public function send(string $phone, string $body): void
            {
                throw new \RuntimeException('تعطّل المزوّد');
            }
        };
    }
}
