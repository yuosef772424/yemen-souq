<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Jobs\DeliverPush;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Services\DeviceRegistrar;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * قواعد المدخل الوحيد للإشعارات.
 *
 * كل اختبار هنا يحرس قاعدة لو سقطت لأنتجت سلوكاً خاطئاً لا خطأً — وهي
 * أسوأ أنواع الانحدار: لا شيء يفشل، والمستخدم وحده يلاحظ.
 */
class NotifierTest extends TestCase
{
    use RefreshDatabase;

    private User $recipient;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipient = User::factory()->create();
        $this->actor = User::factory()->create();
    }

    private function notifier(): Notifier
    {
        return app(Notifier::class);
    }

    private function send(
        ?User $to = null,
        NotificationType $type = NotificationType::NewMessage,
        string $title = 'رسالة جديدة',
        array $data = [],
        ?User $actor = null,
    ): ?AppNotification {
        return $this->notifier()->send(
            recipient: $to ?? $this->recipient,
            type: $type,
            title: $title,
            body: 'نصّ الإشعار',
            data: $data,
            actor: $actor,
        );
    }

    #[Test]
    public function it_writes_a_row_the_moment_the_event_happens(): void
    {
        $notification = $this->send();

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->recipient->id,
            'type' => NotificationType::NewMessage->value,
            'channel' => Channel::InApp->value,
            'read_at' => null,
            'sent_at' => null,
        ]);
    }

    /**
     * الحارس الأول: الفاعل لا يُشعَر بفعله.
     *
     * سقوطه لا يُنتج خطأً — بل بائعاً يتلقى إشعاراً بكل رسالة يكتبها هو.
     */
    #[Test]
    public function the_actor_is_never_notified_of_his_own_action(): void
    {
        $result = $this->send(to: $this->actor, actor: $this->actor);

        $this->assertNull($result);
        $this->assertDatabaseCount('app_notifications', 0);
    }

    #[Test]
    public function a_third_party_actor_does_not_block_the_notification(): void
    {
        $this->assertNotNull($this->send(actor: $this->actor));
        $this->assertDatabaseCount('app_notifications', 1);
    }

    /**
     * الطيّ: خيط واحد = سطر واحد.
     *
     * بلاه، محادثة فيها أربعون رسالة تدفن كل بقية الإشعارات تحتها.
     */
    #[Test]
    public function repeated_events_on_the_same_entity_collapse_into_one_row(): void
    {
        $data = ['entity_type' => 'conversation', 'entity_id' => 7];

        $this->send(title: 'الرسالة الأولى', data: $data);
        $this->send(title: 'الرسالة الثانية', data: $data);
        $this->send(title: 'الرسالة الثالثة', data: $data);

        $this->assertDatabaseCount('app_notifications', 1);

        // الأحدث هو ما يُعرض — الطيّ ليس تجاهلاً للجديد
        $this->assertSame('الرسالة الثالثة', AppNotification::firstOrFail()->title);
    }

    #[Test]
    public function different_entities_do_not_collapse_together(): void
    {
        $this->send(data: ['entity_type' => 'conversation', 'entity_id' => 1]);
        $this->send(data: ['entity_type' => 'conversation', 'entity_id' => 2]);

        $this->assertDatabaseCount('app_notifications', 2);
    }

    /**
     * القراءة تفتح صفحة جديدة.
     *
     * لولا شرط unread في الطيّ لبقي المستخدم يُحدَّث عليه صفٌّ قرأه —
     * فلا يعرف أبداً أن رسالة جديدة وصلت.
     */
    #[Test]
    public function a_read_notification_is_not_collapsed_onto(): void
    {
        $data = ['entity_type' => 'conversation', 'entity_id' => 7];

        $first = $this->send(data: $data);
        $first->markRead();

        $this->send(data: $data);

        $this->assertDatabaseCount('app_notifications', 2);
    }

    /** «اعتُمد إعلانك» حدثٌ يقع مرة — طيّه يبتلع إشعاراً عن إعلان آخر */
    #[Test]
    public function types_that_do_not_collapse_produce_a_row_each(): void
    {
        $data = ['entity_type' => 'listing', 'entity_id' => 3];

        $this->send(type: NotificationType::ListingApproved, data: $data);
        $this->send(type: NotificationType::ListingApproved, data: $data);

        $this->assertDatabaseCount('app_notifications', 2);
    }

    #[Test]
    public function a_muted_type_produces_no_row_at_all(): void
    {
        app(PreferenceResolver::class)->save(
            $this->recipient,
            NotificationType::NewMessage,
            ['in_app' => false, 'push' => false],
        );

        $this->assertNull($this->send());
        $this->assertDatabaseCount('app_notifications', 0);
    }

    /**
     * قرار الإشراف لا يُسكت داخل التطبيق.
     *
     * معلن أسكت الإشعارات ثم رُفض إعلانه لا يعرف لا أنه اختفى ولا لماذا،
     * فيعيد نشره بنفس المخالفة — والإسكات هنا يضاعف عمل الإشراف.
     */
    #[Test]
    public function a_moderation_notification_cannot_be_muted_in_app(): void
    {
        app(PreferenceResolver::class)->save(
            $this->recipient,
            NotificationType::ListingRejected,
            ['in_app' => false, 'push' => false],
        );

        $this->assertNotNull($this->send(type: NotificationType::ListingRejected));
        $this->assertDatabaseCount('app_notifications', 1);
    }

    /** الإجبار على القناة داخل التطبيق وحدها — الدفع يبقى اختيار صاحبه */
    #[Test]
    public function push_stays_optional_even_for_mandatory_types(): void
    {
        Queue::fake();

        app(DeviceRegistrar::class)->register($this->recipient, 'device-1', 'android', 'token-1');

        app(PreferenceResolver::class)->save(
            $this->recipient,
            NotificationType::ListingRejected,
            ['push' => false],
        );

        $this->send(type: NotificationType::ListingRejected);

        Queue::assertNotPushed(DeliverPush::class);
        $this->assertDatabaseCount('app_notifications', 1);
    }

    /**
     * القصّ هنا لا في قاعدة البيانات.
     *
     * العمود varchar(180)، وMySQL في الوضع الصارم يرمي على التجاوز —
     * فعنوان إعلان طويل كان سيُفشل إرسال الرسالة نفسها.
     */
    #[Test]
    public function an_overlong_title_is_truncated_to_fit_the_column(): void
    {
        $this->send(title: str_repeat('ط', 400));

        $this->assertLessThanOrEqual(180, mb_strlen(AppNotification::firstOrFail()->title));
    }

    /** الرسالة النصية والبريد منفَّذتان اليوم — التفضيل وحده يقرر، لا تنفيذٌ ناقص */
    #[Test]
    public function an_implemented_channel_is_allowed_when_the_preference_allows_it(): void
    {
        config(['sms.enabled' => true]);

        app(PreferenceResolver::class)->save(
            $this->recipient,
            NotificationType::NewMessage,
            ['sms' => true, 'email' => true],
        );

        $resolver = app(PreferenceResolver::class);

        $this->assertTrue($resolver->allows($this->recipient, NotificationType::NewMessage, Channel::Sms));
        $this->assertTrue($resolver->allows($this->recipient, NotificationType::NewMessage, Channel::Email));
    }

    /**
     * غياب صفّ التفضيلات ليس رفضاً.
     *
     * الأغلبية الساحقة لا تفتح الإعدادات قط، ولو قُرئ غيابهم كرفض لصمت
     * النظام عن الجميع.
     */
    #[Test]
    public function a_user_with_no_saved_preferences_gets_the_declared_defaults(): void
    {
        $resolver = app(PreferenceResolver::class);

        $this->assertDatabaseCount('notification_preferences', 0);

        $this->assertTrue($resolver->allows($this->recipient, NotificationType::NewMessage, Channel::InApp));
        $this->assertTrue($resolver->allows($this->recipient, NotificationType::NewMessage, Channel::Push));
        $this->assertFalse($resolver->allows($this->recipient, NotificationType::NewMessage, Channel::Sms));
    }

    /** الحفظ الجزئي يكتب صفاً كاملاً — لا يترك بقية الأعمدة لافتراض الهجرة */
    #[Test]
    public function saving_one_channel_writes_the_whole_row(): void
    {
        app(PreferenceResolver::class)->save(
            $this->recipient,
            NotificationType::NewMessage,
            ['push' => false],
        );

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $this->recipient->id,
            'notification_type' => NotificationType::NewMessage->value,
            'in_app' => true,
            'push' => false,
            'sms' => false,
            'email' => false,
        ]);
    }

    /** الحفظ مرتين يحدّث ولا يكرّر — المفتاح مركّب بلا عمود id */
    #[Test]
    public function saving_twice_updates_the_same_row(): void
    {
        $resolver = app(PreferenceResolver::class);

        $resolver->save($this->recipient, NotificationType::NewMessage, ['push' => false]);
        $resolver->save($this->recipient, NotificationType::NewMessage, ['push' => true]);

        $this->assertDatabaseCount('notification_preferences', 1);
        $this->assertTrue($resolver->allows($this->recipient, NotificationType::NewMessage, Channel::Push));
    }
}
