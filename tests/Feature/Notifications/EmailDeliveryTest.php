<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\DeliverEmail;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'notify-me@example.test']);
    }

    private function allowEmail(): void
    {
        app(PreferenceResolver::class)->save($this->user, NotificationType::NewMessage, ['email' => true]);
    }

    private function notify(): ?AppNotification
    {
        return app(Notifier::class)->send(
            recipient: $this->user,
            type: NotificationType::NewMessage,
            title: 'رسالة جديدة',
            body: 'بخصوص: سيارة',
            data: ['entity_type' => 'conversation', 'entity_id' => 1, 'url' => '/conversations/1'],
        );
    }

    #[Test]
    public function the_preference_on_gets_the_email_queued(): void
    {
        Queue::fake();

        $this->allowEmail();

        $this->notify();

        Queue::assertPushed(DeliverEmail::class);
    }

    /** التفضيل مطفأ افتراضياً — لا بريد بلا طلب صريح */
    #[Test]
    public function the_preference_off_by_default_means_no_email(): void
    {
        Queue::fake();

        $this->notify();

        Queue::assertNotPushed(DeliverEmail::class);
    }

    /** نفس حارس الرسالة النصية: الطيّ لا يُطلق بريداً ثانياً */
    #[Test]
    public function a_collapsed_event_does_not_queue_a_second_email(): void
    {
        Queue::fake();

        $this->allowEmail();

        $this->notify();
        $this->notify();
        $this->notify();

        Queue::assertPushed(DeliverEmail::class, 1);
    }

    #[Test]
    public function the_job_sends_the_mailable_to_the_recipient(): void
    {
        // الطابور sync في الاختبارات، فبلا fake تُنفَّذ المهمّة الحقيقية
        // عند notify() نفسها — قبل أن نستدعيها يدوياً هنا. نفس تحفّظ
        // PushDeliveryTest.
        Queue::fake();
        Mail::fake();

        $this->allowEmail();

        $notification = $this->notify();

        (new DeliverEmail($notification->id, $this->user->email))->handle();

        Mail::assertSent(
            NotificationMail::class,
            fn (NotificationMail $mail): bool => $mail->hasTo($this->user->email)
                && $mail->title === $notification->title
        );
    }

    /** التسليم يرفع channel إلى email ويختم sent_at */
    #[Test]
    public function a_delivered_email_is_stamped_on_the_row(): void
    {
        Queue::fake();
        Mail::fake();

        $this->allowEmail();

        $notification = $this->notify();

        (new DeliverEmail($notification->id, $this->user->email))->handle();

        $notification->refresh();

        $this->assertSame(Channel::Email, $notification->channel);
        $this->assertNotNull($notification->sent_at);
    }

    /** إشعار حُذف بين الجدولة والتنفيذ — الخروج بهدوء لا بـModelNotFound */
    #[Test]
    public function a_vanished_notification_is_skipped(): void
    {
        Queue::fake();
        Mail::fake();

        $this->allowEmail();

        $notification = $this->notify();
        $id = $notification->id;
        $notification->delete();

        (new DeliverEmail($id, $this->user->email))->handle();

        Mail::assertNothingSent();
    }
}
