<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\ListingQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ListingQuestionTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $asker;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->asker = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listingFor($this->owner, ['status' => 'active']);
    }

    private function ask(User $user, string $body = 'هل الفحص مسموح قبل الشراء؟')
    {
        return $this->actingAs($user)
            ->from(route('listing.show', $this->listing))
            ->post(route('listing.questions.store', $this->listing), ['body' => $body]);
    }

    #[Test]
    public function a_signed_in_visitor_can_ask(): void
    {
        $this->ask($this->asker)->assertRedirect(route('listing.show', $this->listing));

        $this->assertDatabaseHas('listing_questions', [
            'listing_id' => $this->listing->id,
            'asker_id' => $this->asker->id,
        ]);
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->post(route('listing.questions.store', $this->listing), ['body' => 'سؤال من زائر'])
            ->assertRedirect(route('login'));
    }

    /** لو أراد المالك إضافة معلومة فمكانها الوصف، وهو يملك تعديله */
    #[Test]
    public function the_owner_cannot_ask_on_his_own_listing(): void
    {
        $this->ask($this->owner)->assertSessionHas('error');

        $this->assertDatabaseCount('listing_questions', 0);
    }

    /**
     * أخطر اختبار في الملف.
     *
     * السؤال العام يقرأه كل زائر، فكتابة الرقم فيه تتجاوز
     * ContactRevealController كلياً — ومعه تُفقد أهم إشارة نية شراء،
     * ويُفتح باب حصاد الأرقام آلياً من صفحات الإعلانات.
     */
    #[Test]
    public function a_phone_number_in_a_public_question_is_rejected(): void
    {
        $this->ask($this->asker, 'كلمني على 771234567 وأشتري منك')
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('listing_questions', 0);
    }

    /** ولا يُستثنى البائع: هو الأكثر مصلحةً في نشر رقمه علناً */
    #[Test]
    public function a_phone_number_in_the_owners_answer_is_rejected_too(): void
    {
        $this->ask($this->asker);

        $question = ListingQuestion::firstOrFail();

        $this->actingAs($this->owner)
            ->from(route('listing.show', $this->listing))
            ->post(route('questions.answer', $question), ['answer' => 'اتصل 00967771234567'])
            ->assertSessionHasErrors('answer');

        $this->assertNull($question->fresh()->answered_at);
    }

    #[Test]
    public function a_question_about_a_price_is_not_mistaken_for_a_phone_number(): void
    {
        $this->ask($this->asker, 'هل تقبل 4500000 ريال نقداً؟')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('listing_questions', 1);
    }

    #[Test]
    public function the_owner_answers_and_the_answer_shows_publicly(): void
    {
        $this->ask($this->asker);

        $question = ListingQuestion::firstOrFail();

        $this->actingAs($this->owner)
            ->from(route('listing.show', $this->listing))
            ->post(route('questions.answer', $question), ['answer' => 'نعم الفحص مسموح في أي ورشة']);

        $this->assertNotNull($question->fresh()->answered_at);

        $this->get(route('listing.show', $this->listing))
            ->assertOk()
            ->assertSee('نعم الفحص مسموح في أي ورشة');
    }

    #[Test]
    public function only_the_owner_may_answer(): void
    {
        $this->ask($this->asker);

        $this->actingAs($this->stranger)
            ->post(route('questions.answer', ListingQuestion::firstOrFail()), ['answer' => 'أنا لست البائع'])
            ->assertForbidden();
    }

    /**
     * تاريخ الجواب لا يتحرّك عند التعديل.
     *
     * answered_at يقيس سرعة استجابة البائع؛ تحريكه مع كل تصحيح إملائي
     * يجعل كل بائع يبدو مستجيباً فوراً.
     */
    #[Test]
    public function editing_the_answer_keeps_the_original_answer_time(): void
    {
        $this->ask($this->asker);

        $question = ListingQuestion::firstOrFail();

        $this->actingAs($this->owner)->post(route('questions.answer', $question), ['answer' => 'نعم مسموح']);

        $first = $question->fresh()->answered_at;

        $this->travel(2)->hours();

        $this->actingAs($this->owner)->post(route('questions.answer', $question), ['answer' => 'نعم مسموح في أي ورشة']);

        $this->assertTrue($first->equalTo($question->fresh()->answered_at));
    }

    /**
     * المالك لا يحذف سؤالاً محرجاً — وهذا قرار منتج لا تفصيل تقني.
     *
     * لو استطاع لصارت الأسئلة العامة لوحة دعاية لا مصدر معلومة، وفقد
     * المشتري السبب الوحيد لقراءتها.
     */
    #[Test]
    public function the_owner_cannot_delete_a_question(): void
    {
        $this->ask($this->asker, 'هل السيارة مصدومة؟');

        $this->actingAs($this->owner)
            ->delete(route('questions.destroy', ListingQuestion::firstOrFail()))
            ->assertForbidden();

        $this->assertDatabaseCount('listing_questions', 1);
    }

    #[Test]
    public function the_asker_may_delete_his_own_question(): void
    {
        $this->ask($this->asker);

        $this->actingAs($this->asker)
            ->from(route('listing.show', $this->listing))
            ->delete(route('questions.destroy', ListingQuestion::firstOrFail()));

        $this->assertDatabaseCount('listing_questions', 0);
    }

    #[Test]
    public function a_hidden_question_disappears_from_the_public_page(): void
    {
        $this->ask($this->asker, 'سؤال سيُخفى إدارياً تماماً');

        ListingQuestion::firstOrFail()->forceFill([
            'hidden_at' => now(),
            'hidden_reason' => 'إساءة',
        ])->save();

        $this->get(route('listing.show', $this->listing))
            ->assertOk()
            ->assertDontSee('سؤال سيُخفى إدارياً تماماً');
    }

    /** سؤال معلّق على إعلان لا يزوره المالك يبقى إلى الأبد بلا هذه الصفحة */
    #[Test]
    public function the_inbox_lists_unanswered_questions_across_all_listings(): void
    {
        $second = $this->listingFor($this->owner, ['title' => 'إعلان آخر للمالك']);

        $this->ask($this->asker, 'سؤال على الإعلان الأول');

        $this->actingAs($this->asker)->post(route('listing.questions.store', $second), [
            'body' => 'سؤال على الإعلان الثاني',
        ]);

        $this->actingAs($this->owner)
            ->get(route('account.questions'))
            ->assertOk()
            ->assertSee('سؤال على الإعلان الأول')
            ->assertSee('سؤال على الإعلان الثاني');
    }

    #[Test]
    public function the_inbox_shows_no_questions_from_other_peoples_listings(): void
    {
        $foreign = $this->listingFor($this->stranger, ['title' => 'إعلان شخص آخر']);

        $this->actingAs($this->asker)->post(route('listing.questions.store', $foreign), [
            'body' => 'سؤال لا يخصّ المالك',
        ]);

        $this->actingAs($this->owner)
            ->get(route('account.questions'))
            ->assertOk()
            ->assertDontSee('سؤال لا يخصّ المالك');
    }
}