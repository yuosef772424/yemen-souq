<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * قائمة «إعلاناتي».
 *
 * أهم اختبار هنا هو الثاني: صفحة تعرض إعلانات مستخدم آخر ليست عيباً
 * في العرض بل تسريب بيانات.
 */
class MyListingsTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/account/listings')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_page_shows_only_the_owners_listings(): void
    {
        $mine = $this->listingFor($this->owner, ['title' => 'إعلاني الخاص جداً']);
        $theirs = $this->listingFor($this->stranger, ['title' => 'إعلان شخص آخر']);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=all')
            ->assertOk()
            ->assertSee($mine->title)
            ->assertDontSee($theirs->title);
    }

    /**
     * الانتهاء زمني لا حالة: لا شيء في النظام يكتب status = 'expired'،
     * فتبويب يعتمد على الحالة يبقى صفراً بينما الإعلان منتهٍ فعلاً.
     */
    #[Test]
    public function an_expired_listing_leaves_the_live_tab_without_its_status_changing(): void
    {
        $expired = $this->listingFor($this->owner, [
            'title' => 'إعلان انتهى أمس',
            'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=live')
            ->assertOk()
            ->assertDontSee($expired->title);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=expired')
            ->assertOk()
            ->assertSee($expired->title);

        $this->assertSame('active', $expired->fresh()->status);
    }

    #[Test]
    public function a_deleted_listing_appears_only_in_the_trashed_tab(): void
    {
        $listing = $this->listingFor($this->owner, ['title' => 'إعلان محذوف']);
        $listing->delete();

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=all')
            ->assertOk()
            ->assertDontSee($listing->title);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=trashed')
            ->assertOk()
            ->assertSee($listing->title);
    }

    #[Test]
    public function an_unknown_tab_falls_back_to_live_instead_of_failing(): void
    {
        $this->listingFor($this->owner, ['title' => 'إعلان قائم']);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=../../etc/passwd')
            ->assertOk()
            ->assertSee('إعلان قائم');
    }
}