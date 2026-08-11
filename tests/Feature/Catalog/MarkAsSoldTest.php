<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * زرّ «تم البيع».
 *
 * حلّ محلّ نظام التقييمات: أي إثبات صفقة بين حسابين يمكن تزويره
 * بحساب ثانٍ ينتحل المشتري، فبُنيت الحالة وحدها بلا ادعاء ثقة.
 *
 * وأهم ما يُختبر هنا أن الإعلان يختفي من السوق ويبقى في لوحة مالكه:
 * زرّ يُخفي إعلاناً من كل مكان بلا طريق رجوع هو فقدان لا بيع.
 */
class MarkAsSoldTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->listing = $this->listingFor($this->owner, ['title' => 'تويوتا كورولا موديل 2018']);
    }

    #[Test]
    public function the_owner_marks_a_listing_sold(): void
    {
        $this->actingAs($this->owner)
            ->post(route('account.listings.sold', $this->listing))
            ->assertRedirect();

        $this->listing->refresh();

        $this->assertSame('sold', $this->listing->status);
        $this->assertNotNull($this->listing->sold_at);
    }

    /** الأثر المقصود: يختفي من السوق */
    #[Test]
    public function a_sold_listing_leaves_search_and_browsing(): void
    {
        $this->actingAs($this->owner)->post(route('account.listings.sold', $this->listing));

        $this->assertSame(0, Listing::query()->published()->count());

        $this->get('/')->assertOk()->assertDontSee($this->listing->title);
    }

    /** ويبقى في لوحة مالكه — وإلا فقده */
    #[Test]
    public function it_stays_in_the_owner_dashboard_under_its_own_tab(): void
    {
        $this->actingAs($this->owner)->post(route('account.listings.sold', $this->listing));

        $this->actingAs($this->owner)
            ->get(route('account.listings', ['tab' => 'sold']))
            ->assertOk()
            ->assertSee($this->listing->title);
    }

    #[Test]
    public function the_owner_can_put_it_back_on_display(): void
    {
        $this->actingAs($this->owner)->post(route('account.listings.sold', $this->listing));
        $this->actingAs($this->owner)->post(route('account.listings.sold', $this->listing));

        $this->listing->refresh();

        $this->assertSame('active', $this->listing->status);
        $this->assertNull($this->listing->sold_at);
        $this->assertSame(1, Listing::query()->published()->count());
    }

    /** البيع لا يمدّد الصلاحية ولا يعيدها */
    #[Test]
    public function it_does_not_touch_the_expiry(): void
    {
        $before = $this->listing->expires_at;

        $this->actingAs($this->owner)->post(route('account.listings.sold', $this->listing));
        $this->actingAs($this->owner)->post(route('account.listings.sold', $this->listing));

        $this->assertEquals($before, $this->listing->fresh()->expires_at);
    }

    #[Test]
    public function a_stranger_cannot_mark_it_sold(): void
    {
        $this->actingAs($this->stranger)
            ->post(route('account.listings.sold', $this->listing))
            ->assertForbidden();

        $this->assertSame('active', $this->listing->fresh()->status);
    }

    #[Test]
    public function a_guest_is_sent_to_login(): void
    {
        $this->post(route('account.listings.sold', $this->listing))
            ->assertRedirect(route('login'));
    }

    /** «مباع» على ما لم يُعرض ادعاءٌ لا حدث */
    #[Test]
    public function a_draft_cannot_be_marked_sold(): void
    {
        $draft = $this->listingFor($this->owner, [
            'title' => 'مسودة لم تُنشر بعد',
            'status' => 'draft',
        ]);

        $this->actingAs($this->owner)
            ->post(route('account.listings.sold', $draft))
            ->assertRedirect();

        $this->assertSame('draft', $draft->fresh()->status);
    }

    #[Test]
    public function a_paused_listing_can_be_marked_sold(): void
    {
        $paused = $this->listingFor($this->owner, [
            'title' => 'إعلان موقوف مؤقتاً',
            'status' => 'paused',
        ]);

        $this->actingAs($this->owner)->post(route('account.listings.sold', $paused));

        $this->assertSame('sold', $paused->fresh()->status);
    }

    /** الإرجاع إلى active لا إلى paused — وإلا بدا الزرّ كأنه لم يعمل */
    #[Test]
    public function unmarking_returns_it_to_display_not_to_paused(): void
    {
        $paused = $this->listingFor($this->owner, [
            'title' => 'إعلان موقوف مؤقتاً',
            'status' => 'paused',
        ]);

        $this->actingAs($this->owner)->post(route('account.listings.sold', $paused));
        $this->actingAs($this->owner)->post(route('account.listings.sold', $paused));

        $this->assertSame('active', $paused->fresh()->status);
    }

    #[Test]
    public function the_dashboard_counts_sold_listings(): void
    {
        $this->actingAs($this->owner)->post(route('account.listings.sold', $this->listing));

        $this->actingAs($this->owner)
            ->get(route('account.listings'))
            ->assertOk()
            ->assertSee('المباعة');
    }
}
