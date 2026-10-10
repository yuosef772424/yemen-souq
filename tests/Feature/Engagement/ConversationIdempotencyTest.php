<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Models\Message;
use App\Modules\Engagement\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * إعادة الإرسال بمعرّف client_id: الرسالة تُكتب مرة واحدة مهما تكرر الطلب.
 *
 * ما يُختبر هنا هو الفهرس الفريد والخدمة والنموذج. أما عدم تكرار ما
 * اختُبر في ConversationTest فمقصود: خيط واحد، العزل، الإحصاء.
 */
class ConversationIdempotencyTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $seller;

    private User $buyer;

    private User $stranger;

    private Listing $listing;

    private ConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->seller = User::factory()->create();
        $this->buyer = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listingFor($this->seller, ['status' => 'active']);
        $this->service = app(ConversationService::class);
    }

    private function rateAttempts(User $user): int
    {
        return RateLimiter::attempts("messages:{$user->id}");
    }

    private function start(?string $clientId, string $body = 'هل الإعلان ما زال متاحاً؟')
    {
        return $this->actingAs($this->buyer)->post(route('listing.messages.store', $this->listing), [
            'body' => $body,
            'client_id' => $clientId,
        ]);
    }

    // ─── الخدمة ───

    #[Test]
    public function start_or_continue_with_the_same_client_id_creates_one_message(): void
    {
        $id = (string) Str::uuid();

        $first = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا', $id);
        $second = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا', $id);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->message->id, $second->message->id);
        $this->assertSame($first->conversation->id, $second->conversation->id);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('conversations', 1);
    }

    #[Test]
    public function reply_with_the_same_client_id_creates_one_message(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا')->conversation;
        $id = (string) Str::uuid();

        $first = $this->service->reply($conversation, $this->seller, 'نعم متاح', $id);
        $second = $this->service->reply($conversation, $this->seller, 'نعم متاح', $id);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->message->id, $second->message->id);
        $this->assertDatabaseCount('messages', 2);
    }

    #[Test]
    public function different_client_ids_create_different_messages(): void
    {
        $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا', 'id-one');
        $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا', 'id-two');

        $conversation = Conversation::firstOrFail();

        $this->service->reply($conversation, $this->buyer, 'ثالثة', 'id-three');
        $this->service->reply($conversation, $this->buyer, 'رابعة', 'id-four');

        $this->assertDatabaseCount('messages', 4);
    }

    #[Test]
    public function messages_without_a_client_id_keep_working_as_before(): void
    {
        $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا');
        $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا');

        $conversation = Conversation::firstOrFail();

        $this->service->reply($conversation, $this->buyer, 'ثالثة');
        $this->service->reply($conversation, $this->buyer, 'ثالثة');

        // الفهرس الفريد لا يتعارض فيه NULL — العميل القديم لا يتعطل
        $this->assertDatabaseCount('messages', 4);
    }

    /** معرّفان متطابقان من مستخدمين مختلفين ليسا تكراراً */
    #[Test]
    public function the_key_is_scoped_to_the_sender(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا', 'shared-id')->conversation;

        $reply = $this->service->reply($conversation, $this->seller, 'أهلاً', 'shared-id');

        $this->assertTrue($reply->created);
        $this->assertDatabaseCount('messages', 2);
    }

    /** صفٌّ موجود بالمفتاح ثم استدعاء: تعود الرسالة الموجودة لا نسخة */
    #[Test]
    public function an_existing_row_with_the_key_is_returned_not_duplicated(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا')->conversation;

        $existing = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $this->buyer->id,
            'body' => 'سبقتك',
            'client_id' => 'raced-id',
            'created_at' => now(),
        ]);

        $sent = $this->service->reply($conversation, $this->buyer, 'سبقتك', 'raced-id');

        $this->assertFalse($sent->created);
        $this->assertSame($existing->id, $sent->message->id);
        $this->assertSame(2, Message::count());
    }

    /**
     * التسابق الفعلي: الطلب الثاني لا يجد الصفّ عند البحث، ثم يكتب الأول
     * قبل أن يكتب هو — فيصطدم بالفهرس الفريد ويعيد الرسالة الفائزة.
     */
    #[Test]
    public function losing_a_race_on_reply_returns_the_winning_message(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا')->conversation;

        $this->winnerInsertsAfterFirstLookup($conversation, $this->buyer, 'race-reply');

        $sent = $this->service->reply($conversation, $this->buyer, 'الخاسر', 'race-reply');

        $winner = Message::where('client_id', 'race-reply')->sole();

        $this->assertFalse($sent->created);
        $this->assertSame($winner->id, $sent->message->id);
        $this->assertSame('الفائز', $sent->message->body);
        $this->assertSame(2, Message::count());
    }

    #[Test]
    public function losing_a_race_on_start_or_continue_returns_the_winning_message(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا')->conversation;

        $this->winnerInsertsAfterFirstLookup($conversation, $this->buyer, 'race-start');

        $sent = $this->service->startOrContinue($this->buyer, $this->listing, 'الخاسر', 'race-start');

        $winner = Message::where('client_id', 'race-start')->sole();

        $this->assertFalse($sent->created);
        $this->assertSame($winner->id, $sent->message->id);
        $this->assertSame(2, Message::count());
    }

    /** يُدرج الصفّ الفائز فور انتهاء أول بحث عن المعرّف: بعد أن أخطأه الخاسر وقبل أن يكتب */
    private function winnerInsertsAfterFirstLookup(Conversation $conversation, User $sender, string $clientId): void
    {
        $done = false;

        DB::listen(function ($query) use (&$done, $conversation, $sender, $clientId): void {
            if ($done || ! str_contains($query->sql, 'client_id') || ! str_starts_with(ltrim($query->sql), 'select')) {
                return;
            }

            $done = true;

            DB::table('messages')->insert([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => 'الفائز',
                'client_id' => $clientId,
                'created_at' => now(),
            ]);
        });
    }

    // ─── حدّ المعدل ───

    #[Test]
    public function a_duplicate_does_not_consume_the_rate_limit(): void
    {
        $id = (string) Str::uuid();

        $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا', $id);
        $this->assertSame(1, $this->rateAttempts($this->buyer));

        $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا', $id);
        $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا', $id);

        $this->assertSame(1, $this->rateAttempts($this->buyer));

        $conversation = Conversation::firstOrFail();
        $replyId = (string) Str::uuid();

        $this->service->reply($conversation, $this->buyer, 'ثانية', $replyId);
        $this->service->reply($conversation, $this->buyer, 'ثانية', $replyId);

        $this->assertSame(2, $this->rateAttempts($this->buyer));
    }

    #[Test]
    public function a_duplicate_is_returned_even_after_the_limit_is_reached(): void
    {
        $first = $this->service->startOrContinue($this->buyer, $this->listing, 'الأولى', 'first-id');
        $conversation = $first->conversation;

        for ($i = 0; $i < ConversationService::RATE_LIMIT - 1; $i++) {
            $this->service->reply($conversation, $this->buyer, "رسالة {$i}", "id-{$i}");
        }

        $this->assertSame(ConversationService::RATE_LIMIT, $this->rateAttempts($this->buyer));

        // رسالة جديدة ترفض...
        $this->actingAs($this->buyer)
            ->post(route('account.messages.reply', $conversation), ['body' => 'زيادة', 'client_id' => 'over-limit'])
            ->assertSessionHasErrors('body');

        // ...وإعادة محاولة رسالة وصلت لا تُرفض ولا تُحتسب
        $this->actingAs($this->buyer)
            ->post(route('account.messages.reply', $conversation), ['body' => 'الأولى', 'client_id' => 'id-0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(ConversationService::RATE_LIMIT, $this->rateAttempts($this->buyer));
        $this->assertSame(ConversationService::RATE_LIMIT, Message::count());
    }

    /** رسائل متتابعة بمعرّفات مختلفة تتجاوز الحدّ: يمرّ RATE_LIMIT منها فقط */
    #[Test]
    public function sequential_requests_past_the_limit_are_capped(): void
    {
        $accepted = 0;
        $rejected = 0;

        for ($i = 0; $i < ConversationService::RATE_LIMIT + 5; $i++) {
            try {
                $this->service->startOrContinue($this->buyer, $this->listing, "رسالة {$i}", "burst-{$i}");
                $accepted++;
            } catch (ValidationException) {
                $rejected++;
            }
        }

        $this->assertSame(ConversationService::RATE_LIMIT, $accepted);
        $this->assertSame(5, $rejected);
        $this->assertSame(ConversationService::RATE_LIMIT, Message::count());
        $this->assertSame(ConversationService::RATE_LIMIT, $this->rateAttempts($this->buyer));
    }

    /**
     * الثغرة التي أُغلقت: طلب ثانٍ يصل أثناء معاملة الأول، أي بعد أن
     * اجتاز الأول الفحص وقبل أن يكتب. لو سُجّل الـhit بعد المعاملة لرآه
     * الثاني عدّاداً قديماً ومرّ، فيتجاوز الاثنان الحدّ معاً.
     */
    #[Test]
    public function a_request_arriving_during_another_transaction_cannot_slip_past_the_limit(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا')->conversation;

        // يبقى مكان واحد في الحدّ
        for ($i = 0; $i < ConversationService::RATE_LIMIT - 2; $i++) {
            RateLimiter::hit("messages:{$this->buyer->id}", ConversationService::RATE_WINDOW);
        }

        $this->assertSame(ConversationService::RATE_LIMIT - 1, $this->rateAttempts($this->buyer));

        $outcome = null;
        $fired = false;

        DB::listen(function ($query) use (&$outcome, &$fired, $conversation): void {
            if ($fired || ! str_starts_with(ltrim($query->sql), 'insert into "messages"')) {
                return;
            }

            $fired = true;

            try {
                $this->service->reply($conversation, $this->buyer, 'المتوازي', 'parallel-b');
                $outcome = 'accepted';
            } catch (ValidationException) {
                $outcome = 'rejected';
            }
        });

        $this->service->reply($conversation, $this->buyer, 'الأول', 'parallel-a');

        $this->assertSame('rejected', $outcome);
        $this->assertSame(ConversationService::RATE_LIMIT, $this->rateAttempts($this->buyer));
        $this->assertSame(0, Message::where('client_id', 'parallel-b')->count());
        $this->assertSame(2, Message::count());
    }

    /** الخاسر في سباق على المعرّف يردّ حجزه: التكرار لا يُحتسب حتى على المسار المتأخر */
    #[Test]
    public function losing_a_race_releases_the_reserved_hit(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا')->conversation;
        $this->assertSame(1, $this->rateAttempts($this->buyer));

        $this->winnerInsertsAfterFirstLookup($conversation, $this->buyer, 'race-rate-reply');
        $reply = $this->service->reply($conversation, $this->buyer, 'الخاسر', 'race-rate-reply');

        $this->assertFalse($reply->created);
        $this->assertSame(1, $this->rateAttempts($this->buyer));
    }

    #[Test]
    public function losing_a_race_on_start_or_continue_releases_the_reserved_hit(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا')->conversation;
        $this->assertSame(1, $this->rateAttempts($this->buyer));

        $this->winnerInsertsAfterFirstLookup($conversation, $this->buyer, 'race-rate-start');
        $sent = $this->service->startOrContinue($this->buyer, $this->listing, 'الخاسر', 'race-rate-start');

        $this->assertFalse($sent->created);
        $this->assertSame(1, $this->rateAttempts($this->buyer));
    }

    /** تكرار يصل والحدّ ممتلئ: لا يُرفض ولا يلمس العدّاد (مسار البحث المبكر) */
    #[Test]
    public function an_early_duplicate_at_the_limit_neither_rejects_nor_touches_the_counter(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'الأولى', 'early-dup')->conversation;

        for ($i = 0; $i < ConversationService::RATE_LIMIT - 1; $i++) {
            RateLimiter::hit("messages:{$this->buyer->id}", ConversationService::RATE_WINDOW);
        }

        $this->assertSame(ConversationService::RATE_LIMIT, $this->rateAttempts($this->buyer));

        $again = $this->service->reply($conversation, $this->buyer, 'الأولى', 'early-dup');
        $viaStart = $this->service->startOrContinue($this->buyer, $this->listing, 'الأولى', 'early-dup');

        $this->assertFalse($again->created);
        $this->assertFalse($viaStart->created);
        $this->assertSame(ConversationService::RATE_LIMIT, $this->rateAttempts($this->buyer));
    }

    /** فشل الكتابة باستثناء آخر يردّ الحجز أيضاً: محاولة فاشلة لا تستهلك الحدّ */
    #[Test]
    public function a_failed_write_releases_the_reserved_hit(): void
    {
        $conversation = $this->service->startOrContinue($this->buyer, $this->listing, 'مرحبا')->conversation;

        DB::listen(function ($query): void {
            if (str_starts_with(ltrim($query->sql), 'insert into "messages"')) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $this->service->reply($conversation, $this->buyer, 'تفشل', 'will-fail');
            $this->fail('expected the write to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(1, $this->rateAttempts($this->buyer));
    }

    /** محاولة رُفضت بالحدّ لا تترك محادثة فارغة ولا تستهلك الحدّ */
    #[Test]
    public function a_rejected_attempt_leaves_no_conversation_behind(): void
    {
        for ($i = 0; $i < ConversationService::RATE_LIMIT; $i++) {
            RateLimiter::hit("messages:{$this->buyer->id}", 60);
        }

        $this->start((string) Str::uuid())->assertSessionHasErrors('body');

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame(ConversationService::RATE_LIMIT, $this->rateAttempts($this->buyer));
    }

    // ─── الويب ───

    #[Test]
    public function a_double_submit_of_the_same_hidden_field_creates_one_message(): void
    {
        $id = (string) Str::uuid();

        $this->start($id)->assertRedirect();
        $this->start($id)->assertRedirect();

        $conversation = Conversation::firstOrFail();

        $this->assertDatabaseCount('messages', 1);

        $this->actingAs($this->buyer)->post(route('account.messages.reply', $conversation), ['body' => 'ثانية', 'client_id' => 'reply-1']);
        $this->actingAs($this->buyer)->post(route('account.messages.reply', $conversation), ['body' => 'ثانية', 'client_id' => 'reply-1'])
            ->assertRedirect(route('account.messages.show', $conversation));

        $this->assertDatabaseCount('messages', 2);
        $this->assertSame(1, (int) $this->listing->fresh()->contacts_count);
    }

    #[Test]
    public function a_duplicate_does_not_notify_the_other_party_twice(): void
    {
        $this->start('notify-id');
        $this->start('notify-id');

        $this->assertSame(1, $this->seller->appNotifications()->count());
    }

    #[Test]
    public function the_forms_render_a_fresh_uuid_hidden_field(): void
    {
        $this->start((string) Str::uuid());
        $conversation = Conversation::firstOrFail();

        $pages = [
            route('listing.show', $this->listing) => $this->stranger,
            route('account.messages.show', $conversation) => $this->buyer,
        ];

        foreach ($pages as $url => $user) {
            $first = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $second = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $pattern = '/name="client_id"[^>]*value="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/';

            $this->assertSame(1, preg_match($pattern, $first, $a), "no client_id field at {$url}");
            $this->assertSame(1, preg_match($pattern, $second, $b));
            $this->assertNotSame($a[1], $b[1], 'كل عرض للنموذج معرّف جديد');
        }
    }

    // ─── التحقق ───

    #[Test]
    public function client_id_is_optional_but_validated_when_present(): void
    {
        $this->start(null)->assertSessionHasNoErrors();
        $this->start('has spaces!')->assertSessionHasErrors('client_id');
        $this->start(str_repeat('a', 65))->assertSessionHasErrors('client_id');
        $this->start(str_repeat('a', 64))->assertSessionHasNoErrors();
        $this->start('Valid-ID-123')->assertSessionHasNoErrors();
    }

    // ─── العزل ───

    /** مستخدم ثالث: لا قراءة ولا كتابة ولا وسم، ولا تتسرب المحادثة في قائمته */
    #[Test]
    public function a_third_user_is_isolated_from_the_thread(): void
    {
        $this->start('iso-1');

        $conversation = Conversation::firstOrFail();

        $this->actingAs($this->stranger)
            ->get(route('account.messages.show', $conversation))
            ->assertForbidden();

        // فتح الصفحة يسم مقروءاً — ورفض الغريب يعني ألا يسم أحداً
        $this->assertNull($conversation->messages()->firstOrFail()->read_at);

        $this->actingAs($this->stranger)
            ->post(route('account.messages.reply', $conversation), ['body' => 'دخيل', 'client_id' => 'iso-2'])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 1);

        $this->actingAs($this->stranger)
            ->get(route('account.messages'))
            ->assertOk()
            ->assertDontSee($this->listing->title);
    }

    /** المعرّف لا يكشف رسالة غيرك: تخمينه يُنشئ رسالتك أنت */
    #[Test]
    public function reusing_another_users_client_id_does_not_return_their_message(): void
    {
        $this->start('victim-id', 'سرّ المشتري');

        $other = $this->listingFor($this->seller, ['status' => 'active']);

        $sent = $this->service->startOrContinue($this->stranger, $other, 'رسالتي', 'victim-id');

        $this->assertTrue($sent->created);
        $this->assertSame('رسالتي', $sent->message->body);
        $this->assertSame($this->stranger->id, $sent->message->sender_id);
    }

    /** conversation_id في الجسم لا يُقرأ: لا كتابة في محادثة غيرك عبر الإرسال الأول */
    #[Test]
    public function a_conversation_id_in_the_body_cannot_target_someone_elses_thread(): void
    {
        $this->start('victim-thread');

        $victim = Conversation::firstOrFail();

        $this->actingAs($this->stranger)
            ->post(route('listing.messages.store', $this->listing), [
                'body' => 'محاولة حقن',
                'client_id' => 'inject-1',
                'conversation_id' => $victim->id,
            ]);

        $this->assertSame(1, $victim->messages()->count());
        $this->assertSame(
            1,
            Conversation::where('buyer_id', $this->stranger->id)->count(),
            'الغريب يفتح خيطه هو، لا يكتب في خيط غيره'
        );
    }
}
