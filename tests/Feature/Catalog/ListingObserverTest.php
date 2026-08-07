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

class ListingObserverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Category $category;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['YER', 'ريال يمني', true], ['USD', 'دولار', false], ['SAR', 'ريال سعودي', false]] as [$code, $name, $isBase]) {
            Currency::create([
                'code' => $code, 'name_ar' => $name, 'name_en' => $code,
                'symbol' => $code, 'decimals' => 2, 'is_base' => $isBase,
            ]);
        }

        foreach (['YER' => 1, 'USD' => 500, 'SAR' => 133] as $code => $rate) {
            ExchangeRate::create([
                'currency_code' => $code,
                'rate_to_base' => $rate,
                'effective_date' => now()->toDateString(),
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

    private function makeListing(array $overrides = []): Listing
    {
        return Listing::create(array_merge([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'سيارة تويوتا',
            'price_amount' => 100,
            'currency_code' => 'USD',
            'status' => 'active',
        ], $overrides));
    }

    #[Test]
    public function it_normalizes_the_title_on_save(): void
    {
        $listing = $this->makeListing(['title' => 'شقة للإيجار']);

        $this->assertSame('شقه للايجار', $listing->title_normalized);
        $this->assertSame('شقة للإيجار', $listing->title, 'العنوان الأصلي يبقى كما كتبه المعلن');
    }

    #[Test]
    public function it_converts_the_price_to_base_currency(): void
    {
        $listing = $this->makeListing(['price_amount' => 100, 'currency_code' => 'USD']);

        $this->assertSame('50000.00', $listing->price_in_base);
        $this->assertSame('100.00', $listing->price_amount, 'السعر الأصلي لا يتغير');
        $this->assertSame('USD', $listing->currency_code);
    }

    #[Test]
    public function it_stores_the_rate_used_for_auditing(): void
    {
        $listing = $this->makeListing(['currency_code' => 'SAR', 'price_amount' => 1000]);

        $this->assertSame('133.000000', $listing->rate_used);
        $this->assertSame('133000.00', $listing->price_in_base);
    }

    #[Test]
    public function identical_numbers_in_different_currencies_get_different_base_prices(): void
    {
        // المشكلة التي بُني المشروع لحلها
        $usd = $this->makeListing(['price_amount' => 100, 'currency_code' => 'USD']);
        $yer = $this->makeListing(['price_amount' => 100, 'currency_code' => 'YER']);

        $this->assertSame('50000.00', $usd->price_in_base);
        $this->assertSame('100.00', $yer->price_in_base);
    }

    #[Test]
    public function price_filtering_uses_base_value_not_the_raw_number(): void
    {
        $this->makeListing(['price_amount' => 100, 'currency_code' => 'USD']);  // 50,000
        $this->makeListing(['price_amount' => 100, 'currency_code' => 'YER']);  // 100
        $this->makeListing(['price_amount' => 200, 'currency_code' => 'SAR']);  // 26,600

        $results = Listing::priceBetween(20000, 60000)->get();

        $this->assertCount(2, $results);
    }

    #[Test]
    public function it_generates_a_unique_slug(): void
    {
        $first = $this->makeListing();
        $second = $this->makeListing();

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertNotEmpty($first->slug);
    }

    #[Test]
    public function it_leaves_base_price_null_when_price_is_on_request(): void
    {
        $listing = $this->makeListing([
            'price_amount' => null,
            'currency_code' => null,
            'price_type' => 'on_request',
        ]);

        $this->assertNull($listing->price_in_base);
    }

    #[Test]
    public function it_recalculates_base_price_when_currency_changes(): void
    {
        $listing = $this->makeListing(['price_amount' => 100, 'currency_code' => 'USD']);

        $listing->update(['currency_code' => 'SAR']);

        $this->assertSame('13300.00', $listing->fresh()->price_in_base);
    }

    #[Test]
    public function it_records_price_history_on_change(): void
    {
        $listing = $this->makeListing(['price_amount' => 100, 'currency_code' => 'USD']);

        $listing->update(['price_amount' => 80]);

        $history = $listing->priceHistory()->first();

        $this->assertNotNull($history);
        $this->assertSame('100.00', $history->old_amount);
        $this->assertSame('80.00', $history->new_amount);
    }

    #[Test]
    public function it_does_not_record_history_when_price_is_unchanged(): void
    {
        $listing = $this->makeListing();

        $listing->update(['title' => 'عنوان جديد']);

        $this->assertCount(0, $listing->priceHistory()->get());
    }
}
