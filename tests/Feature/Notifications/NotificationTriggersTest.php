<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Modules\Admin\Services\ListingModerator;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Models\ListingQuestion;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * الأحداث الحقيقية التي تُنتج إشعاراً.
 *
 * هذه هي الاختبارات التي تحرس الميزة كما يعيشها المستخدم: من أرسل،
 * ومن يجب أن يُخبَر، وبأيّ نصّ. الوحدة كلها بلا هذه الوصلات مكتبةٌ لا
 * يستدعيها أحد.
 */
class NotificationTriggersTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $seller;

    private User $buyer;

    private User $admin;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->seller = User::factory()->create();
        $this->buyer = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->listing = $this->listingFor($this->seller, ['status' => 'active']);
    }

    private function notificationsFor(User $user)
    {
        return AppNotification::query()->forUser($user)->get();
    }

    // ═══ المحادثات ═══

    #[Test]
    public function a_first_message_notifies_the_seller_only(): void
    {
        $this->actingAs($this->buyer)
            ->post(route('listing.messages.store', $this->listing), ['body' => 'ما زال متاحاً؟']);

        $this->assertCount(1, $this->notificationsFor($this->seller));
        $this->assertCount(0, $this->notificationsFor($this->buyer));

        $notification = $this->notificationsFor($this->seller)->first();

        $this->assertSame(NotificationType::NewMessage, $notification->type);
        $this->assertStringContainsString($this->buyer->displayName(), $notification->title);
    }

    /**
     * نصّ الرسالة لا يُنسخ في الإشعار.
     *
     * سببان: الشاشة المقفلة يراها من يقف بجانب صاحب الجهاز، ونسخة ثانية
     * من النصّ في جدول له سياسة حذف مختلفة. عنوان الإعلان بديلٌ كافٍ.
     */
    #[Test]
    public function the_private_message_text_never_reaches_the_notification(): void
    {
        $secret = 'أنزل السعر إلى مليون ونص وأشتريها اليوم';

        $this->actingAs($this->buyer)
            ->post(route('listing.messages.store', $this->listing), ['body' => $secret]);

        $notification = $this->notificationsFor($this->seller)->first();

        $this->assertStringNotContainsString($secret, $notification->title.' '.$notification->body);
        $this->assertStringContainsString($this->listing->title, (string) $notification->body);
    }

    #[Test]
    public function a_reply_notifies_the_other_side_not_the_sender(): void
    {
        $this->actingAs($this->buyer)
            ->post(route('listing.messages.store', $this->listing), ['body' => 'سؤال']);

        $conversation = Conversation::firstOrFail();

        $this->actingAs($this->seller)
            ->post(route('account.messages.reply', $conversation), ['body' => 'نعم متاح']);

        $this->assertCount(1, $this->notificationsFor($this->seller));
        $this->assertCount(1, $this->notificationsFor($this->buyer));
    }

    /** خيط واحد = سطر واحد مهما بلغ عدد الرسائل */
    #[Test]
    public function many_messages_in_one_thread_stay_one_notification(): void
    {
        foreach (['الأولى', 'الثانية', 'الثالثة', 'الرابعة'] as $body) {
            $this->actingAs($this->buyer)
                ->post(route('listing.messages.store', $this->listing), ['body' => $body]);
        }

        $this->assertCount(1, $this->notificationsFor($this->seller));
    }

    // ═══ الأسئلة ═══

    #[Test]
    public function a_question_notifies_the_listing_owner(): void
    {
        $this->actingAs($this->buyer)
            ->post(route('listing.questions.store', $this->listing), ['body' => 'كم عدد المالكين السابقين؟']);

        $notification = $this->notificationsFor($this->seller)->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationType::NewQuestion, $notification->type);

        // الوجهة صندوق الأسئلة: صفحة الإعلان تعرض السؤال بلا حقل جواب
        $this->assertSame(route('account.questions'), $notification->url());
    }

    /** السؤال معروض للجميع على صفحة الإعلان — فنسخه لا يكشف مكتوماً */
    #[Test]
    public function the_public_question_text_does_reach_the_notification(): void
    {
        $this->actingAs($this->buyer)
            ->post(route('listing.questions.store', $this->listing), ['body' => 'كم عدد المالكين السابقين؟']);

        $this->assertStringContainsString(
            'المالكين',
            (string) $this->notificationsFor($this->seller)->first()->body
        );
    }

    #[Test]
    public function an_answer_notifies_the_asker(): void
    {
        $this->actingAs($this->buyer)
            ->post(route('listing.questions.store', $this->listing), ['body' => 'كم الممشى؟']);

        $question = ListingQuestion::firstOrFail();

        $this->actingAs($this->seller)
            ->post(route('questions.answer', $question), ['answer' => 'مئة ألف كيلومتر']);

        $notifications = $this->notificationsFor($this->buyer);

        $this->assertCount(1, $notifications);
        $this->assertSame(NotificationType::QuestionAnswered, $notifications->first()->type);
    }

    /**
     * تصحيح الجواب لا يُشعر السائل ثانيةً.
     *
     * إشعارٌ ثانٍ عن نفس الجواب يُقرأ كجوابٍ ثانٍ، فيفتح السائل الصفحة
     * ليجد ما قرأه أمس. نفس حجّة تجميد answered_at.
     */
    #[Test]
    public function editing_the_answer_does_not_notify_again(): void
    {
        $this->actingAs($this->buyer)
            ->post(route('listing.questions.store', $this->listing), ['body' => 'كم الممشى؟']);

        $question = ListingQuestion::firstOrFail();

        $this->actingAs($this->seller)
            ->post(route('questions.answer', $question), ['answer' => 'مئة ألف']);

        $this->actingAs($this->seller)
            ->post(route('questions.answer', $question), ['answer' => 'مئة ألف كيلومتر تقريباً']);

        $this->assertCount(1, $this->notificationsFor($this->buyer));
    }

    // ═══ الإشراف ═══

    #[Test]
    public function approving_a_listing_notifies_its_owner(): void
    {
        app(ListingModerator::class)->approve($this->listing, $this->admin);

        $notification = $this->notificationsFor($this->seller)->first();

        $this->assertNotNull($notification);
        $this->assertSame(NotificationType::ListingApproved, $notification->type);
        $this->assertSame(route('listing.show', $this->listing), $notification->url());
    }

    /**
     * سبب الرفض يُنسخ في جسد الإشعار.
     *
     * «رُفض إعلانك» وحدها تُنتج إعادة نشرٍ بنفس المخالفة، ورفضاً ثانياً
     * بعدها — فيتضاعف عمل الإشراف والمعلن معاً.
     */
    #[Test]
    public function rejecting_a_listing_carries_the_reason_to_its_owner(): void
    {
        app(ListingModerator::class)->reject($this->listing, $this->admin, 'الصور غير واضحة');

        $notification = $this->notificationsFor($this->seller)->first();

        $this->assertSame(NotificationType::ListingRejected, $notification->type);
        $this->assertStringContainsString('الصور غير واضحة', (string) $notification->body);

        // لا صفحة عامة لمرفوض — الوجهة لوحة صاحبه
        $this->assertSame(route('account.listings'), $notification->url());
    }

    /** الإيقاف والحذف بلا إشعار — قرار موثَّق، انظر ListingModerator */
    #[Test]
    public function pausing_a_listing_notifies_no_one(): void
    {
        app(ListingModerator::class)->pause($this->listing, $this->admin, 'بلاغ قيد الفحص');

        $this->assertDatabaseCount('app_notifications', 0);
    }

    /**
     * مشرف يبتّ في إعلانه هو لا يُشعر نفسه.
     *
     * حالة واقعية: المشرف يجرّب المنصة بحساب واحد.
     */
    #[Test]
    public function an_admin_moderating_his_own_listing_gets_nothing(): void
    {
        $own = $this->listingFor($this->admin, ['status' => 'pending']);

        app(ListingModerator::class)->approve($own, $this->admin);

        $this->assertDatabaseCount('app_notifications', 0);
    }
}
