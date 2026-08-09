<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * العيب: published() كان يتجاهل expires_at، وترتيب المميّز كان يتجاهل
 * featured_until — مع وجود فهرس لكل منهما في الجدول.
 */
class ListingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_expired_listing_is_not_published(): void
    {
        $expired = Listing::factory()->create(['expires_at' => now()->subDay()]);
        $live = Listing::factory()->create(['expires_at' => now()->addDays(10)]);

        $ids = Listing::published()->pluck('id')->all();

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    #[Test]
    public function a_listing_without_an_expiry_date_stays_published(): void
    {
        $listing = Listing::factory()->create(['expires_at' => null]);

        $this->assertContains($listing->id, Listing::published()->pluck('id')->all());
    }

    #[Test]
    public function an_expired_promotion_no_longer_ranks_first(): void
    {
        $staleFeatured = Listing::factory()->create([
            'is_featured' => true,
            'featured_until' => now()->subDay(),
            'published_at' => now()->subMonth(),
        ]);

        $ordinary = Listing::factory()->create([
            'is_featured' => false,
            'published_at' => now(),
        ]);

        $ids = Listing::published()->featuredFirst()->orderByDesc('published_at')->pluck('id')->all();

        $this->assertSame($ordinary->id, $ids[0]);
        $this->assertFalse($staleFeatured->fresh()->isFeaturedNow());
    }

    #[Test]
    public function a_live_promotion_still_ranks_first(): void
    {
        $featured = Listing::factory()->create([
            'is_featured' => true,
            'featured_until' => now()->addWeek(),
            'published_at' => now()->subMonth(),
        ]);

        Listing::factory()->create(['is_featured' => false, 'published_at' => now()]);

        $ids = Listing::published()->featuredFirst()->orderByDesc('published_at')->pluck('id')->all();

        $this->assertSame($featured->id, $ids[0]);
    }

    #[Test]
    public function the_arabic_slug_survives(): void
    {
        $listing = Listing::factory()->create(['title' => 'تويوتا كورولا موديل 2018', 'slug' => null]);

        // كان الناتج listing-x8f3k2m1 لكل إعلان
        $this->assertStringContainsString('تويوتا', $listing->slug);
        $this->assertStringNotContainsString(' ', $listing->slug);
    }
}