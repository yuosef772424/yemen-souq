<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    private function notify(?User $to = null, string $title = 'رسالة جديدة', array $data = []): AppNotification
    {
        return app(Notifier::class)->send(
            recipient: $to ?? $this->user,
            type: NotificationType::NewMessage,
            title: $title,
            body: 'نصّ',
            data: $data,
        );
    }

    #[Test]
    public function the_page_needs_a_login(): void
    {
        $this->get(route('account.notifications'))->assertRedirect(route('login'));
    }

    #[Test]
    public function it_lists_only_my_notifications(): void
    {
        $this->notify(title: 'إشعاري');
        $this->notify(to: $this->stranger, title: 'إشعار غيري');

        $this->actingAs($this->user)
            ->get(route('account.notifications'))
            ->assertOk()
            ->assertSee('إشعاري')
            ->assertDontSee('إشعار غيري');
    }

    #[Test]
    public function an_empty_box_says_so(): void
    {
        $this->actingAs($this->user)
            ->get(route('account.notifications'))
            ->assertOk()
            ->assertSee('لا إشعارات بعد');
    }

    #[Test]
    public function opening_marks_it_read_and_goes_to_the_target(): void
    {
        $notification = $this->notify(data: ['url' => '/listing/some-slug']);

        $this->actingAs($this->user)
            ->post(route('account.notifications.open', $notification))
            ->assertRedirect('/listing/some-slug');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /** إعلان حُذف بعد الإشعار عنه — الرجوع للقائمة لا 404 على رابط أرسله النظام */
    #[Test]
    public function a_notification_without_a_target_returns_to_the_list(): void
    {
        $notification = $this->notify();

        $this->actingAs($this->user)
            ->post(route('account.notifications.open', $notification))
            ->assertRedirect(route('account.notifications'));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    #[Test]
    public function i_cannot_open_someone_elses_notification(): void
    {
        $notification = $this->notify(to: $this->stranger);

        $this->actingAs($this->user)
            ->post(route('account.notifications.open', $notification))
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    #[Test]
    public function read_all_clears_the_box_for_me_only(): void
    {
        $this->notify(data: ['entity_type' => 'conversation', 'entity_id' => 1]);
        $this->notify(data: ['entity_type' => 'conversation', 'entity_id' => 2]);
        $mine = $this->notify(to: $this->stranger);

        $this->actingAs($this->user)
            ->post(route('account.notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, AppNotification::query()->forUser($this->user)->unread()->count());
        $this->assertNull($mine->fresh()->read_at);
    }

    /**
     * الوسم لا يتكرّر.
     *
     * read_at هو «متى رآه» ويقع مرة. تحريكه مع كل فتحة يجعل «منذ متى
     * وهو مقروء؟» سؤالاً بلا جواب.
     */
    #[Test]
    public function opening_twice_keeps_the_first_read_stamp(): void
    {
        $notification = $this->notify();

        $this->actingAs($this->user)->post(route('account.notifications.open', $notification));

        $first = $notification->fresh()->read_at;

        $this->travel(5)->minutes();

        $this->actingAs($this->user)->post(route('account.notifications.open', $notification));

        $this->assertTrue($first->equalTo($notification->fresh()->read_at));
    }

    /** الرابط في الترويسة يصل الميزة بالموقع — بلاه لا يجدها أحد */
    #[Test]
    public function the_header_links_to_the_box(): void
    {
        $this->actingAs($this->user)
            ->get(route('account.favorites'))
            ->assertOk()
            ->assertSee(route('account.notifications'));
    }
}
