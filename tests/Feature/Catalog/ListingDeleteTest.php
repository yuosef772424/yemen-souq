<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * الحذف منطقي.
 *
 * الاختبار الأخير هو سبب هذا القرار: الحذف النهائي يمسح معه
 * listing_daily_stats وlisting_price_history بأثر القيود التسلسلية —
 * أي أنه يُتلف البيانات التي بُني عليها المشروع، وبضغطة واحدة.
 */
class ListingDeleteTest extends TestCase
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
        $this->listing = $this->listingFor($this->owner, ['status' => 'active']);
    }

    #[Test]
    public function the_owner_deletes_softly(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('account.listings.destroy', $this->listing))
            ->assertRedirect(route('account.listings', ['tab' => 'trashed']));

        $this->assertSoftDeleted('listings', ['id' => $this->listing->id]);
    }

    #[Test]
    public function a_stranger_cannot_delete(): void
    {
        $this->actingAs($this->stranger)
            ->delete(route('account.listings.destroy', $this->listing))
            ->assertForbidden();

        $this->assertNotSoftDeleted('listings', ['id' => $this->listing->id]);
    }

    #[Test]
    public function a_deleted_listing_disappears_from_search_and_from_its_own_page(): void
    {
        $title = $this->listing->title;

        $this->listing->delete();

        $this->get('/')->assertOk()->assertDontSee($title);
        $this->get(route('listing.show', $this->listing))->assertNotFound();
    }

    #[Test]
    public function the_owner_can_restore_what_was_deleted(): void
    {
        $this->listing->delete();

        $this->actingAs($this->owner)
            ->patch(route('account.listings.restore', $this->listing))
            ->assertRedirect(route('account.listings'));

        $this->assertNotSoftDeleted('listings', ['id' => $this->listing->id]);
    }

    #[Test]
    public function a_stranger_cannot_restore(): void
    {
        $this->listing->delete();

        $this->actingAs($this->stranger)
            ->patch(route('account.listings.restore', $this->listing))
            ->assertForbidden();

        $this->assertSoftDeleted('listings', ['id' => $this->listing->id]);
    }

    /** الحذف لا يُتلف الإحصاءات ولا تاريخ السعر — هذا سبب اختيار الحذف المنطقي */
    #[Test]
    public function deleting_keeps_the_statistics_and_the_price_history(): void
    {
        \Illuminate\Support\Facades\DB::table('listing_daily_stats')->insert([
            'listing_id' => $this->listing->id,
            'stat_date' => now()->toDateString(),
            'views' => 42,
        ]);

        $this->actingAs($this->owner)->delete(route('account.listings.destroy', $this->listing));

        $this->assertDatabaseHas('listing_daily_stats', [
            'listing_id' => $this->listing->id,
            'views' => 42,
        ]);
    }
}