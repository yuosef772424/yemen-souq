<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * markDelivered: عمود channel أبعد قناة بلغها، لا آخر قناة كتبت.
 *
 * ثلاث مهامّ (Push وSms وEmail) قد تنجح على نفس الصفّ بترتيب وصولٍ لا
 * علاقة له بأهمّية القناة — والحارس هنا يمنع قناةً أقرب انتهت متأخرةً
 * من إخفاء سجلّ قناة أبعد سبقتها.
 */
class AppNotificationMarkDeliveredTest extends TestCase
{
    use RefreshDatabase;

    private function notification(): AppNotification
    {
        $user = User::factory()->create();

        return AppNotification::create([
            'user_id' => $user->id,
            'type' => NotificationType::NewMessage,
            'title' => 'عنوان',
            'channel' => Channel::InApp,
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function a_further_channel_overwrites_a_closer_one(): void
    {
        $notification = $this->notification();

        $notification->markDelivered(Channel::Push);
        $notification->markDelivered(Channel::Sms);

        $this->assertSame(Channel::Sms, $notification->fresh()->channel);
    }

    /** واتساب سبق البريد فوصلت الرسالة عبره — بريدٌ لاحقٌ لا يُنزل السجلّ */
    #[Test]
    public function a_closer_channel_does_not_overwrite_a_further_one(): void
    {
        $notification = $this->notification();

        $notification->markDelivered(Channel::Sms);
        $notification->markDelivered(Channel::Push);

        $this->assertSame(Channel::Sms, $notification->fresh()->channel);
    }

    #[Test]
    public function it_always_stamps_sent_at_when_it_writes(): void
    {
        $notification = $this->notification();

        $this->assertNull($notification->sent_at);

        $notification->markDelivered(Channel::Push);

        $this->assertNotNull($notification->fresh()->sent_at);
    }
}
