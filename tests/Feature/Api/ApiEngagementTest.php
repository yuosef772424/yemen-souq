<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Models\ListingQuestion;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\SeedsMarketplace;
use Tests\TestCase;

/**
 * المفضلة والأسئلة والمحادثات والبلاغات والإشعارات عبر الـAPI.
 */
class ApiEngagementTest extends TestCase
{
    use RefreshDatabase, SeedsMarketplace;

    private User $seller;

    private User $buyer;

    private $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();

        $this->seller = User::factory()->create();
        $this->buyer = User::factory()->create();
        $this->listing = $this->listing(['user_id' => $this->seller->id]);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear("messages:{$this->buyer->id}");
        RateLimiter::clear("questions:{$this->buyer->id}");

        parent::tearDown();
    }

    // ─── المفضلة ───

    /**
     * الفعلان مُتماثلان: الضغطة المُعادة على شبكة متقطّعة لا تعود بالحالة
     * إلى نقطة البداية — وهو ما يمنعه PUT/DELETE بدل «بدِّل».
     */
    #[Test]
    public function favoriting_is_idempotent(): void
    {
        $url = "/api/v1/listings/{$this->listing->slug}/favorite";

        $this->actingAs($this->buyer, 'sanctum')->putJson($url)->assertOk()
            ->assertJsonPath('is_favorited', true);
        $this->actingAs($this->buyer, 'sanctum')->putJson($url)->assertOk()
            ->assertJsonPath('is_favorited', true);

        $this->assertDatabaseCount('favorites', 1);
        // العدّاد لا ينحرف عن عدد الصفوف — insertOrIgnore داخل معاملة
        $this->assertSame(1, $this->listing->fresh()->favorites_count);

        $this->actingAs($this->buyer, 'sanctum')->deleteJson($url)->assertOk()
            ->assertJsonPath('is_favorited', false);
        $this->actingAs($this->buyer, 'sanctum')->deleteJson($url)->assertOk();

        $this->assertDatabaseCount('favorites', 0);
        $this->assertSame(0, $this->listing->fresh()->favorites_count);
    }

    #[Test]
    public function favorites_require_a_token(): void
    {
        $this->putJson("/api/v1/listings/{$this->listing->slug}/favorite")->assertUnauthorized();
    }

    // ─── الأسئلة ───

    #[Test]
    public function a_buyer_asks_and_the_seller_answers(): void
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/questions", [
                'body' => 'هل السيارة مفحوصة ومضمونة من الحوادث؟',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_answered', false);

        $question = ListingQuestion::first();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/questions/{$question->id}/answer", [
                'answer' => 'نعم مفحوصة بالكامل.',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_answered', true);
    }

    /**
     * أهم اختبار في هذا الملف.
     *
     * حارس الأرقام يعيش في `AskQuestionRequest`. ولو كُتب طلبٌ جديد
     * للـAPI لسقطت القاعدة بصمت، ولصار التطبيق بابَ التفافٍ على حارسٍ
     * قائم في الويب — يتجاوز آلية كشف الرقم ويفتح حصاده آلياً.
     */
    #[Test]
    public function a_phone_number_in_a_public_question_is_rejected(): void
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/questions", [
                'body' => 'كلمني على الرقم 771234567 للاتفاق على السعر',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('listing_questions', 0);
    }

    /** والمنع يشمل جواب المالك — هو صاحب المصلحة في نشر رقمه */
    #[Test]
    public function a_phone_number_in_the_answer_is_rejected_too(): void
    {
        $question = ListingQuestion::create([
            'listing_id' => $this->listing->id,
            'asker_id' => $this->buyer->id,
            'body' => 'هل السيارة مفحوصة ومضمونة من الحوادث؟',
        ]);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/questions/{$question->id}/answer", [
                'answer' => 'اتصل على 0771234567',
            ])
            ->assertStatus(422);
    }

    /** المالك يجيب ولا يحذف، والسائل يحذف ولا يجيب */
    #[Test]
    public function the_owner_cannot_delete_an_awkward_question(): void
    {
        $question = ListingQuestion::create([
            'listing_id' => $this->listing->id,
            'asker_id' => $this->buyer->id,
            'body' => 'هل السيارة مفحوصة ومضمونة من الحوادث؟',
        ]);

        $this->actingAs($this->seller, 'sanctum')
            ->deleteJson("/api/v1/questions/{$question->id}")
            ->assertForbidden();

        $this->actingAs($this->buyer, 'sanctum')
            ->deleteJson("/api/v1/questions/{$question->id}")
            ->assertOk();
    }

    #[Test]
    public function the_owner_cannot_ask_on_their_own_listing(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/questions", [
                'body' => 'هل هذه السيارة جيدة يا ترى؟',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'question_not_allowed');
    }

    // ─── المحادثات ───

    #[Test]
    public function a_buyer_opens_one_thread_no_matter_how_often_they_write(): void
    {
        $url = "/api/v1/listings/{$this->listing->slug}/conversation";

        $this->actingAs($this->buyer, 'sanctum')
            ->postJson($url, ['body' => 'السلام عليكم، هل ما زالت متاحة؟'])
            ->assertCreated();

        $this->actingAs($this->buyer, 'sanctum')
            ->postJson($url, ['body' => 'وكم آخر سعر؟'])
            ->assertCreated();

        // القيد unique(listing_id, buyer_id) — والخدمة firstOrCreate
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    /**
     * تسريب محادثة أثقل من تسريب إعلان — ولا استثناء لصاحب الإعلان في
     * خيوط مشترين آخرين.
     */
    #[Test]
    public function a_third_party_cannot_read_a_thread(): void
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/conversation", [
                'body' => 'السلام عليكم، هل ما زالت متاحة؟',
            ])->assertCreated();

        $conversation = Conversation::first();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertForbidden();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'مرحبا'])
            ->assertForbidden();
    }

    /**
     * القراءة لا تسم — التطبيق قد يجلب الخيط مسبقاً في الخلفية، ووسمٌ
     * ضمنيّ يُخفي إشعاراً عن شاشة لم يرها أحد.
     */
    #[Test]
    public function reading_a_thread_does_not_mark_it_read(): void
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/conversation", [
                'body' => 'السلام عليكم، هل ما زالت متاحة؟',
            ])->assertCreated();

        $conversation = Conversation::first();

        $this->actingAs($this->seller, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('marked', 1);

        $this->actingAs($this->seller, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertJsonPath('data.unread_count', 0);
    }

    #[Test]
    public function a_seller_cannot_message_themselves(): void
    {
        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/conversation", ['body' => 'مرحبا بنفسي'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'conversation_not_allowed');
    }

    // ─── الإشعارات ───

    /**
     * الحلقة كاملة: رسالة من التطبيق تُنتج إشعاراً يقرؤه الطرف الآخر
     * من التطبيق.
     */
    #[Test]
    public function a_message_produces_a_notification_for_the_other_party(): void
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/conversation", [
                'body' => 'السلام عليكم، هل ما زالت متاحة؟',
            ])->assertCreated();

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread', 1);

        // ونصّ الرسالة لا يُنسخ في الإشعار — الشاشة المقفلة يراها من
        // يقف بجانب صاحبها
        $notification = AppNotification::first();
        $this->assertStringNotContainsString('ما زالت متاحة', (string) $notification->body);

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonStructure(['data' => [['id', 'type', 'title', 'body', 'data', 'read', 'created_at']]]);
    }

    /** الفاعل لا يُشعَر بفعله — أكثر حارس يُنسى */
    #[Test]
    public function the_sender_is_not_notified_of_their_own_message(): void
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/conversation", [
                'body' => 'السلام عليكم، هل ما زالت متاحة؟',
            ])->assertCreated();

        $this->actingAs($this->buyer, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('unread', 0);
    }

    #[Test]
    public function a_notification_belongs_to_its_owner_alone(): void
    {
        $notification = AppNotification::create([
            'user_id' => $this->seller->id,
            'type' => 'new_question',
            'title' => 'سؤال جديد',
            'channel' => 'in_app',
            'created_at' => now(),
        ]);

        $this->actingAs($this->buyer, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertForbidden();

        $this->actingAs($this->seller, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);
    }

    // ─── الأجهزة ───

    /**
     * تسجيل الجهاز مُتماثل، والرمز الواحد لا يخدم حسابين: جهاز يخرج منه
     * مستخدم ويدخل آخر يحمل نفس رمز الدفع — ولو بقي مربوطاً بالأول
     * لوصلت رسائله الخاصة إلى جهاز يحمله شخص آخر.
     */
    #[Test]
    public function registering_a_device_detaches_the_token_from_other_accounts(): void
    {
        $this->actingAs($this->buyer, 'sanctum')->putJson('/api/v1/devices', [
            'device_uuid' => 'device-uuid-0001',
            'platform' => 'android',
            'push_token' => 'shared-token',
        ])->assertOk()->assertJsonPath('data.push_enabled', true);

        $this->actingAs($this->seller, 'sanctum')->putJson('/api/v1/devices', [
            'device_uuid' => 'device-uuid-0001',
            'platform' => 'android',
            'push_token' => 'shared-token',
        ])->assertOk();

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $this->buyer->id,
            'push_token' => null,
        ]);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $this->seller->id,
            'push_token' => 'shared-token',
        ]);
    }

    #[Test]
    public function forgetting_a_device_keeps_its_row(): void
    {
        $this->actingAs($this->buyer, 'sanctum')->putJson('/api/v1/devices', [
            'device_uuid' => 'device-uuid-0001',
            'platform' => 'ios',
            'push_token' => 'token-1',
        ])->assertOk();

        $this->actingAs($this->buyer, 'sanctum')
            ->deleteJson('/api/v1/devices/device-uuid-0001')
            ->assertOk();

        // الصفّ سجلّ جهاز لا سجلّ رمز — المطلوب قطع التسليم لا محو الأثر
        $this->assertDatabaseCount('user_devices', 1);
        $this->assertDatabaseHas('user_devices', ['device_uuid' => 'device-uuid-0001', 'push_token' => null]);
    }

    // ─── البلاغات ───

    #[Test]
    public function a_second_open_report_is_refused(): void
    {
        $url = "/api/v1/listings/{$this->listing->slug}/reports";

        $this->actingAs($this->buyer, 'sanctum')
            ->postJson($url, ['reason' => 'fraud'])
            ->assertCreated();

        $this->actingAs($this->buyer, 'sanctum')
            ->postJson($url, ['reason' => 'spam'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'report_not_allowed');

        $this->assertDatabaseCount('listing_reports', 1);
    }

    #[Test]
    public function other_requires_details(): void
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson("/api/v1/listings/{$this->listing->slug}/reports", ['reason' => 'other'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('details');
    }
}
