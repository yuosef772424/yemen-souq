<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * العيبان المُصلَحان: published() كان يتجاهل expires_at، وترتيب المميّز
 * كان يتجاهل featured_until — مع وجود فهرس لكل منهما في الجدول.
 *
 * لم يسقط أي اختبار قائم عند الإصلاح، لأن الاختبارات القائمة تترك
 * العمودين null. هذا الملف يغطي الحالتين اللتين لم تكونا مغطّاتين.
 */
class ListingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['YER', true], ['USD', false]] as [$code, $isBase]) {
            Currency::create([
                'code' => $code, 'name_ar' => $code, 'name_en' => $code,
                'symbol' => $code, 'decimals' => 2, 'is_base' => $isBase,
            ]);
        }

        foreach (['YER' => 1, 'USD' => 500] as $code => $rate) {
            ExchangeRate::create([
                'currency_code' => $code,
                'rate_to_base' => $rate,
                'effective_date' => now()->subYear()->toDateString(),
                'source' => 'test',
                'created_at' => now(),
            ]);
        }

        $this->user = User::factory()->create();
        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
    }

    #[Test]
    public function an_expired_listing_is_not_published(): void
    {
        $expired = $this->listing(['expires_at' => now()->subDay()]);
        $live = $this->listing(['expires_at' => now()->addDays(10)]);

        $ids = Listing::published()->pluck('id')->all();

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    #[Test]
    public function a_listing_without_an_expiry_date_stays_published(): void
    {
        $listing = $this->listing(['expires_at' => null]);

        $this->assertContains($listing->id, Listing::published()->pluck('id')->all());
    }

    #[Test]
    public function an_expired_promotion_no_longer_ranks_first(): void
    {
        $stale = $this->listing([
            'is_featured' => true,
            'featured_until' => now()->subDay(),
            'published_at' => now()->subMonth(),
        ]);

        $ordinary = $this->listing([
            'is_featured' => false,
            'published_at' => now(),
        ]);

        $ids = Listing::published()->featuredFirst()->orderByDesc('published_at')->pluck('id')->all();

        $this->assertSame($ordinary->id, $ids[0]);
        $this->assertFalse($stale->fresh()->isFeaturedNow());
    }

    #[Test]
    public function a_live_promotion_still_ranks_first(): void
    {
        $featured = $this->listing([
            'is_featured' => true,
            'featured_until' => now()->addWeek(),
            'published_at' => now()->subMonth(),
        ]);

        $this->listing(['is_featured' => false, 'published_at' => now()]);

        $ids = Listing::published()->featuredFirst()->orderByDesc('published_at')->pluck('id')->all();

        $this->assertSame($featured->id, $ids[0]);
        $this->assertTrue($featured->fresh()->isFeaturedNow());
    }

    #[Test]
    public function a_promotion_without_an_end_date_stays_first(): void
    {
        $featured = $this->listing([
            'is_featured' => true,
            'featured_until' => null,
            'published_at' => now()->subMonth(),
        ]);

        $this->listing(['is_featured' => false, 'published_at' => now()]);

        $ids = Listing::published()->featuredFirst()->orderByDesc('published_at')->pluck('id')->all();

        $this->assertSame($featured->id, $ids[0]);
    }

    #[Test]
    public function the_arabic_slug_survives(): void
    {
        $listing = $this->listing(['title' => 'تويوتا كورولا موديل 2018']);

        // كان الناتج listing-x8f3k2m1 لكل إعلان بلا استثناء
        $this->assertStringContainsString('تويوتا', $listing->slug);
        $this->assertStringContainsString('2018', $listing->slug);
        $this->assertStringNotContainsString(' ', $listing->slug);
    }

    #[Test]
    public function two_listings_with_the_same_title_get_different_slugs(): void
    {
        $first = $this->listing(['title' => 'شقة للإيجار في حدة']);
        $second = $this->listing(['title' => 'شقة للإيجار في حدة']);

        $this->assertNotSame($first->slug, $second->slug);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::create(array_merge([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'سيارة تويوتا كورولا',
            'description' => 'الحالة ممتازة.',
            'price_amount' => 1000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ], $attributes));
    }
}