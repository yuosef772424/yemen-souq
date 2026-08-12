<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Contracts\PushSender;
use App\Modules\Notifications\DTO\PushMessage;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\DeliverPush;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Senders\FcmPushSender;
use App\Modules\Notifications\Senders\LogPushSender;
use App\Modules\Notifications\Services\DeviceRegistrar;
use App\Modules\Notifications\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * الدفع: من الجدولة إلى المزوّد وإلى تنظيف الرموز الميتة.
 */
class PushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
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
    public function a_registered_device_gets_the_push_queued(): void
    {
        Queue::fake();

        app(DeviceRegistrar::class)->register($this->user, 'device-1', 'android', 'token-1');

        $this->notify();

        Queue::assertPushed(DeliverPush::class);
    }

    /**
     * لا جهاز = لا مهمّة، والصفّ موجود على أي حال.
     *
     * جدولة مهمّة تكتشف عند تنفيذها أن لا رموز هي عملٌ كامل مقابل لا شيء
     * — والحساب بلا تطبيق هو الحالة الافتراضية اليوم لا الاستثناء.
     */
    #[Test]
    public function no_device_means_no_job_but_the_row_still_exists(): void
    {
        Queue::fake();

        $this->notify();

        Queue::assertNotPushed(DeliverPush::class);
        $this->assertDatabaseCount('app_notifications', 1);
    }

    /** التسليم يرفع channel إلى push ويختم sent_at */
    #[Test]
    public function a_delivered_push_is_stamped_on_the_row(): void
    {
        // الطابور sync في الاختبارات، فبلا fake تُنفَّذ المهمّة الحقيقية
        // عند الإرسال ويُختم الصفّ قبل أن نستدعيها يدوياً — فيمرّ
        // الاختبار على عملٍ ليس هو ما يقيسه
        Queue::fake();

        app(DeviceRegistrar::class)->register($this->user, 'device-1', 'android', 'token-1');

        $notification = $this->notify();

        (new DeliverPush($notification->id, ['token-1']))
            ->handle(new LogPushSender, app(DeviceRegistrar::class));

        $notification->refresh();

        $this->assertSame(Channel::Push, $notification->channel);
        $this->assertNotNull($notification->sent_at);
    }

    /**
     * الرمز الذي رفضه المزوّد يُحذف.
     *
     * بلا هذه الحلقة يتراكم في الجدول رموزٌ لأجهزة حُذف عنها التطبيق،
     * فيصير معظم ما يُرسَل إلى العدم ويرتفع معدّل الفشل عند المزوّد.
     */
    #[Test]
    public function tokens_the_provider_rejects_are_pruned(): void
    {
        Queue::fake();

        $registrar = app(DeviceRegistrar::class);

        $registrar->register($this->user, 'device-dead', 'android', 'dead-token');
        $registrar->register($this->user, 'device-live', 'ios', 'live-token');

        $notification = $this->notify();

        (new DeliverPush($notification->id, ['dead-token', 'live-token']))
            ->handle($this->senderRejecting(['dead-token']), $registrar);

        $this->assertSame(['live-token'], $registrar->tokensFor($this->user));

        // الصفّ باقٍ بلا رمز — سجلّ جهاز لا سجلّ رمز
        $this->assertDatabaseHas('user_devices', [
            'device_uuid' => 'device-dead',
            'push_token' => null,
        ]);
    }

    /**
     * سقوط المزوّد لا يُفشل المهمّة.
     *
     * الإشعار موجود في القائمة، وغاية الدفع تنبيهٌ فوقه. مهمّة تفشل
     * وتُعاد مرتين ثم تسقط في failed_jobs مقابل تنبيهٍ فائت ليست مقايضة
     * صحيحة.
     */
    #[Test]
    public function a_failing_provider_does_not_throw(): void
    {
        Queue::fake();

        app(DeviceRegistrar::class)->register($this->user, 'device-1', 'android', 'token-1');

        $notification = $this->notify();

        (new DeliverPush($notification->id, ['token-1']))
            ->handle(new FcmPushSender, app(DeviceRegistrar::class));

        // لم يُرمَ شيء، ولم يُختم التسليم
        $this->assertNull($notification->refresh()->sent_at);
    }

    /** إشعار حُذف بين الجدولة والتنفيذ — الخروج بهدوء لا بـModelNotFound */
    #[Test]
    public function a_vanished_notification_is_skipped(): void
    {
        $notification = $this->notify();
        $id = $notification->id;
        $notification->delete();

        (new DeliverPush($id, ['token-1']))
            ->handle(new LogPushSender, app(DeviceRegistrar::class));

        $this->assertDatabaseCount('app_notifications', 0);
    }

    /**
     * التهدئة: عشر رسائل متتابعة تُنتج دفعاً واحداً.
     *
     * الطيّ يحمي القائمة، والتهدئة تحمي الجيب. وهما مسألتان مختلفتان —
     * القائمة تُقرأ مرة والاهتزاز يقع في كل مرة.
     */
    #[Test]
    public function a_second_event_within_the_cooldown_does_not_push_again(): void
    {
        config(['notifications.push.cooldown_seconds' => 300]);

        app(DeviceRegistrar::class)->register($this->user, 'device-1', 'android', 'token-1');

        $notification = $this->notify();

        // محاكاة تسليم ناجح للأولى
        $notification->forceFill(['sent_at' => now()])->save();

        Queue::fake();

        $this->notify();

        Queue::assertNotPushed(DeliverPush::class);
        $this->assertDatabaseCount('app_notifications', 1);
    }

    #[Test]
    public function the_cooldown_expires(): void
    {
        config(['notifications.push.cooldown_seconds' => 60]);

        app(DeviceRegistrar::class)->register($this->user, 'device-1', 'android', 'token-1');

        $notification = $this->notify();
        $notification->forceFill(['sent_at' => now()->subMinutes(5)])->save();

        Queue::fake();

        $this->notify();

        Queue::assertPushed(DeliverPush::class);
    }

    #[Test]
    public function an_unknown_push_driver_stops_the_boot_instead_of_going_quiet(): void
    {
        config(['notifications.push.driver' => 'firebasee']);

        $this->expectException(\InvalidArgumentException::class);

        app(PushSender::class);
    }

    #[Test]
    public function the_unimplemented_provider_says_so_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        (new FcmPushSender)->send(['token'], new PushMessage(1, 'عنوان', null));
    }

    /** @param list<string> $dead */
    private function senderRejecting(array $dead): PushSender
    {
        return new class($dead) implements PushSender
        {
            public function __construct(private readonly array $dead) {}

            public function send(array $tokens, PushMessage $message): array
            {
                return array_values(array_intersect($tokens, $this->dead));
            }
        };
    }
}
