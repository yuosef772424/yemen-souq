<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\SeedsMarketplace;
use Tests\TestCase;

/**
 * إعادة الإرسال بـclient_id وعزل المحادثات عبر الـAPI.
 *
 * الاستجابة: 201 للإنشاء، و200 للتكرار، والرسالة نفسها بالمعرّف نفسه.
 */
class ApiConversationIdempotencyTest extends TestCase
{
    use RefreshDatabase, SeedsMarketplace;

    private User $seller;

    private User $buyer;

    private User $stranger;

    private $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();

        $this->seller = User::factory()->create();
        $this->buyer = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listing(['user_id' => $this->seller->id]);

        RateLimiter::clear("messages:{$this->buyer->id}");
    }

    private function startUrl(): string
    {
        return "/api/v1/listings/{$this->listing->slug}/conversation";
    }

    private function open(?string $clientId = null): Conversation
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson($this->startUrl(), ['body' => 'السلام عليكم', 'client_id' => $clientId])
            ->assertSuccessful();

        return Conversation::firstOrFail();
    }

    // ─── التكرار ───

    #[Test]
    public function starting_twice_with_the_same_client_id_returns_the_same_message(): void
    {
        $id = (string) Str::uuid();

        $first = $this->actingAs($this->buyer, 'sanctum')
            ->postJson($this->startUrl(), ['body' => 'السلام عليكم', 'client_id' => $id])
            ->assertCreated();

        $second = $this->actingAs($this->buyer, 'sanctum')
            ->postJson($this->startUrl(), ['body' => 'السلام عليكم', 'client_id' => $id])
            ->assertOk();

        $this->assertSame($first->json('sent_message.id'), $second->json('sent_message.id'));
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('conversations', 1);
    }

    #[Test]
    public function replying_twice_with_the_same_client_id_returns_the_same_message(): void
    {
        $conversation = $this->open();
        $url = "/api/v1/conversations/{$conversation->id}/messages";
        $id = (string) Str::uuid();

        $first = $this->actingAs($this->seller, 'sanctum')
            ->postJson($url, ['body' => 'أهلاً', 'client_id' => $id])
            ->assertCreated();

        $second = $this->actingAs($this->seller, 'sanctum')
            ->postJson($url, ['body' => 'أهلاً', 'client_id' => $id])
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.body'), $second->json('data.body'));
        $this->assertSame(2, Message::count());
    }

    #[Test]
    public function different_client_ids_create_different_messages(): void
    {
        $conversation = $this->open();
        $url = "/api/v1/conversations/{$conversation->id}/messages";

        $a = $this->actingAs($this->seller, 'sanctum')->postJson($url, ['body' => 'أهلاً', 'client_id' => 'a-1'])->assertCreated();
        $b = $this->actingAs($this->seller, 'sanctum')->postJson($url, ['body' => 'أهلاً', 'client_id' => 'b-2'])->assertCreated();

        $this->assertNotSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(3, Message::count());
    }

    /** العميل القديم لا يتعطل: الحقل اختياري */
    #[Test]
    public function client_id_is_optional_in_the_api(): void
    {
        $conversation = $this->open();
        $url = "/api/v1/conversations/{$conversation->id}/messages";

        $this->actingAs($this->seller, 'sanctum')->postJson($url, ['body' => 'أهلاً'])->assertCreated();
        $this->actingAs($this->seller, 'sanctum')->postJson($url, ['body' => 'أهلاً'])->assertCreated();

        $this->assertSame(3, Message::count());
    }

    #[Test]
    public function an_invalid_client_id_is_rejected(): void
    {
        $this->actingAs($this->buyer, 'sanctum')
            ->postJson($this->startUrl(), ['body' => 'السلام عليكم', 'client_id' => 'bad id; drop'])
            ->assertStatus(422);

        $this->actingAs($this->buyer, 'sanctum')
            ->postJson($this->startUrl(), ['body' => 'السلام عليكم', 'client_id' => str_repeat('x', 65)])
            ->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    #[Test]
    public function a_duplicate_does_not_raise_the_rate_counter(): void
    {
        $id = (string) Str::uuid();

        $this->open($id);
        $this->open($id);
        $this->open($id);

        $this->assertSame(1, RateLimiter::attempts("messages:{$this->buyer->id}"));
    }

    // ─── العزل ───

    /** مستخدم ثالث: 403 في show وmessages وreply وread، وقائمته لا تتضمن الخيط */
    #[Test]
    public function a_third_user_is_isolated_from_every_conversation_endpoint(): void
    {
        $conversation = $this->open();

        $this->actingAs($this->stranger, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->actingAs($this->stranger, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversation->id}/messages")
            ->assertForbidden();

        $this->actingAs($this->stranger, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'دخيل', 'client_id' => 'x-1'])
            ->assertForbidden();

        $this->actingAs($this->stranger, 'sanctum')
            ->postJson("/api/v1/conversations/{$conversation->id}/read")
            ->assertForbidden();

        // الوسم المرفوض لم يمسّ شيئاً، والكتابة المرفوضة لم تُنشئ شيئاً
        $this->assertNull(Message::firstOrFail()->read_at);
        $this->assertDatabaseCount('messages', 1);

        $this->actingAs($this->stranger, 'sanctum')
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->seller, 'sanctum')
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** conversation_id في جسم الإرسال الأول لا يُقرأ: لا كتابة في خيط غيرك */
    #[Test]
    public function a_conversation_id_in_the_body_cannot_target_someone_elses_thread(): void
    {
        $victim = $this->open('victim-1');

        $this->actingAs($this->stranger, 'sanctum')
            ->postJson($this->startUrl(), [
                'body' => 'محاولة حقن',
                'client_id' => 'inject-1',
                'conversation_id' => $victim->id,
            ])
            ->assertCreated();

        $this->assertSame(1, $victim->messages()->count());
        $this->assertSame(1, Conversation::where('buyer_id', $this->stranger->id)->count());
    }

    /** معرّف رسالة غيرك لا يعيد رسالته: النطاق (خيط، مرسل، معرّف) */
    #[Test]
    public function another_users_client_id_never_returns_their_message(): void
    {
        $this->open('shared-id');

        $response = $this->actingAs($this->stranger, 'sanctum')
            ->postJson($this->startUrl(), ['body' => 'رسالتي أنا', 'client_id' => 'shared-id'])
            ->assertCreated();

        $this->assertSame('رسالتي أنا', $response->json('sent_message.body'));
        $this->assertSame(2, Message::count());
    }
}
