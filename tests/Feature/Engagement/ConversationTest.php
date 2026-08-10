<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $seller;

    private User $buyer;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->seller = User::factory()->create();
        $this->buyer = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listingFor($this->seller, ['status' => 'active']);
    }

    private function send(User $user, string $body = 'هل الإعلان ما زال متاحاً؟')
    {
        return $this->actingAs($user)->post(route('listing.messages.store', $this->listing), ['body' => $body]);
    }

    #[Test]
    public function a_buyer_starts_a_thread_and_lands_in_it(): void
    {
        $this->send($this->buyer)->assertRedirect(
            route('account.messages.show', Conversation::firstOrFail())
        );

        $this->assertDatabaseHas('conversations', [
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
        ]);
        $this->assertDatabaseCount('messages', 1);
    }

    /**
     * القيد unique(listing_id, buyer_id) يعني خيطاً واحداً.
     *
     * ضغطة مزدوجة أو عودة بعد أسبوع لا تُنتج خيطين للحديث نفسه — ولهذا
     * الخدمة firstOrCreate لا create.
     */
    #[Test]
    public function sending_twice_continues_the_same_thread(): void
    {
        $this->send($this->buyer, 'الرسالة الأولى');
        $this->send($this->buyer, 'الرسالة الثانية');

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    /**
     * البائع لا يفتح محادثة على إعلانه.
     *
     * لو فتحها لأنشأ صفاً يكون فيه المشتري والبائع شخصاً واحداً — خيط
     * يحدّث فيه نفسه، ويفسد كل إحصاء عن «المحادثات لكل إعلان».
     */
    #[Test]
    public function the_owner_cannot_start_a_thread_on_his_own_listing(): void
    {
        $this->send($this->seller)->assertSessionHas('error');

        $this->assertDatabaseCount('conversations', 0);
    }

    #[Test]
    public function no_new_thread_on_a_closed_listing(): void
    {
        $this->listing->forceFill(['expires_at' => now()->subDay()])->save();

        $this->send($this->buyer)->assertSessionHas('error');

        $this->assertDatabaseCount('conversations', 0);
    }

    /**
     * الخيط القائم يبقى مفتوحاً بعد انتهاء الإعلان.
     *
     * قطعه يضرّ الطرفين — قد يكونا في منتصف اتفاق. الفرق بين «لا تفتح
     * جديداً» و«لا تكمل القائم» هو كل الفرق.
     */
    #[Test]
    public function an_existing_thread_survives_the_listing_expiring(): void
    {
        $this->send($this->buyer);

        $conversation = Conversation::firstOrFail();

        $this->listing->delete();

        $this->actingAs($this->buyer)
            ->post(route('account.messages.reply', $conversation), ['body' => 'ما زلت مهتماً'])
            ->assertRedirect(route('account.messages.show', $conversation));

        $this->assertDatabaseCount('messages', 2);
    }

    /** تسريب محادثة أثقل من تسريب إعلان */
    #[Test]
    public function a_third_party_cannot_read_or_write_the_thread(): void
    {
        $this->send($this->buyer);

        $conversation = Conversation::firstOrFail();

        $this->actingAs($this->stranger)
            ->get(route('account.messages.show', $conversation))
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->post(route('account.messages.reply', $conversation), ['body' => 'أنا لست طرفاً'])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 1);
    }

    #[Test]
    public function both_sides_see_the_thread_in_their_list(): void
    {
        $this->send($this->buyer);

        foreach ([$this->buyer, $this->seller] as $party) {
            $this->actingAs($party)
                ->get(route('account.messages'))
                ->assertOk()
                ->assertSee($this->listing->title);
        }
    }

    /**
     * «مقروءة» تعني أن المستقبل رآها.
     *
     * وسم رسائل المرسل نفسه يجعل المؤشّر كذباً — وهو مؤشّر يُبنى عليه
     * سلوك: أعيد الإرسال أم أنتظر.
     */
    #[Test]
    public function opening_the_thread_marks_only_the_other_sides_messages(): void
    {
        $this->send($this->buyer);

        $conversation = Conversation::firstOrFail();

        $this->actingAs($this->buyer)->get(route('account.messages.show', $conversation));

        $this->assertNull($conversation->messages()->firstOrFail()->read_at);

        $this->actingAs($this->seller)->get(route('account.messages.show', $conversation));

        $this->assertNotNull($conversation->messages()->firstOrFail()->read_at);
    }

    #[Test]
    public function the_first_message_counts_as_a_contact_and_later_ones_do_not(): void
    {
        $this->send($this->buyer, 'الأولى');
        $this->send($this->buyer, 'الثانية');
        $this->send($this->buyer, 'الثالثة');

        $this->assertSame(1, (int) $this->listing->fresh()->contacts_count);
    }

    #[Test]
    public function the_seller_id_comes_from_the_listing_not_the_request(): void
    {
        $this->actingAs($this->buyer)->post(route('listing.messages.store', $this->listing), [
            'body' => 'محاولة تزييف البائع',
            'seller_id' => $this->stranger->id,
        ]);

        $this->assertSame($this->seller->id, Conversation::firstOrFail()->seller_id);
    }

    #[Test]
    public function an_empty_message_is_rejected(): void
    {
        $this->send($this->buyer, '')->assertSessionHasErrors('body');

        $this->assertDatabaseCount('conversations', 0);
    }

    /** الرسائل الخاصة لا يُمنع فيها الرقم: بين طرفين لا معنى للمنع */
    #[Test]
    public function a_phone_number_is_allowed_in_a_private_message(): void
    {
        $this->send($this->buyer, 'رقمي 771234567 كلمني')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('messages', 1);
    }

    #[Test]
    public function the_service_refuses_to_start_for_the_owner(): void
    {
        $this->assertFalse(app(ConversationService::class)->canStart($this->seller, $this->listing));
        $this->assertTrue(app(ConversationService::class)->canStart($this->buyer, $this->listing));
    }
}