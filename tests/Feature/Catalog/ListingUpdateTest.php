<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ListingUpdateTest extends TestCase
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

        $this->listing = $this->listingFor($this->owner, [
            'title' => 'تويوتا كورولا موديل 2018',
            'price_amount' => 5000,
            'currency_code' => 'SAR',
            'price_type' => 'fixed',
            'status' => 'active',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'تويوتا كورولا موديل 2019 وكالة',
            'description' => 'وصف محدَّث للإعلان.',
            'category_id' => $this->listing->category_id,
            'location_id' => $this->listing->location_id,
            'price_type' => 'fixed',
            'price_amount' => 5000,
            'currency_code' => 'SAR',
            'contact_phone' => '+967771234567',
            ...$overrides,
        ];
    }

    #[Test]
    public function the_owner_reaches_the_edit_form(): void
    {
        $this->actingAs($this->owner)
            ->get(route('account.listings.edit', $this->listing))
            ->assertOk()
            ->assertSee($this->listing->title);
    }

    #[Test]
    public function a_stranger_is_refused_on_both_reading_and_writing(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('account.listings.edit', $this->listing))
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->put(route('account.listings.update', $this->listing), $this->payload())
            ->assertForbidden();

        $this->assertSame('تويوتا كورولا موديل 2018', $this->listing->fresh()->title);
    }

    #[Test]
    public function the_owner_can_change_the_title_and_the_normalized_column_follows(): void
    {
        $this->actingAs($this->owner)
            ->put(route('account.listings.update', $this->listing), $this->payload())
            ->assertRedirect(route('account.listings.edit', $this->listing));

        $fresh = $this->listing->fresh();

        $this->assertSame('تويوتا كورولا موديل 2019 وكالة', $fresh->title);
        $this->assertStringContainsString('2019', (string) $fresh->title_normalized);
        $this->assertStringContainsString('2019', (string) $fresh->search_blob);
    }

    /**
     * الرابط لا يتغيّر مع العنوان.
     *
     * قد يكون منشوراً في محادثة أو محفوظاً في مفضلة زائر — وتغييره
     * يكسر ما لا نراه، بلا أي مكسب.
     */
    #[Test]
    public function editing_the_title_does_not_change_the_slug(): void
    {
        $slug = $this->listing->slug;

        $this->actingAs($this->owner)
            ->put(route('account.listings.update', $this->listing), $this->payload());

        $this->assertSame($slug, $this->listing->fresh()->slug);
    }

    /**
     * أخطر اختبار في الملف: الحقول التي ليست في UpdateListingRequest
     * يجب أن تُتجاهل حتى وهي حاضرة في الطلب.
     */
    #[Test]
    public function privileged_fields_sent_in_the_request_are_ignored(): void
    {
        $this->actingAs($this->owner)->put(
            route('account.listings.update', $this->listing),
            $this->payload([
                'user_id' => $this->stranger->id,
                'status' => 'sold',
                'is_featured' => 1,
                'expires_at' => now()->addYears(5)->toDateTimeString(),
                'views_count' => 999999,
            ])
        );

        $fresh = $this->listing->fresh();

        $this->assertSame($this->owner->id, $fresh->user_id);
        $this->assertSame('active', $fresh->status);
        $this->assertFalse($fresh->is_featured);
        $this->assertTrue($fresh->expires_at->lessThan(now()->addYear()));
        $this->assertSame(0, (int) $fresh->views_count);
    }

    #[Test]
    public function changing_the_price_recomputes_the_base_value_and_is_recorded(): void
    {
        $this->actingAs($this->owner)->put(
            route('account.listings.update', $this->listing),
            $this->payload(['price_amount' => 6000, 'currency_code' => 'SAR'])
        );

        $fresh = $this->listing->fresh();

        $this->assertSame('6000.00', $fresh->price_amount);
        $this->assertSame(6000.0 * 140, (float) $fresh->price_in_base);
        $this->assertDatabaseHas('listing_price_history', [
            'listing_id' => $this->listing->id,
            'new_amount' => 6000,
            'new_currency' => 'SAR',
        ]);
    }

    #[Test]
    public function a_deleted_listing_cannot_be_edited(): void
    {
        $this->listing->delete();

        $this->actingAs($this->owner)
            ->get(route('account.listings.edit', $this->listing))
            ->assertNotFound();
    }
}