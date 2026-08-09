<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private SearchQueryBuilder $search;
    private User $user;
    private Category $category;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = app(SearchQueryBuilder::class);

        foreach ([['YER', true], ['USD', false], ['SAR', false]] as [$code, $isBase]) {
            Currency::create([
                'code' => $code, 'name_ar' => $code, 'name_en' => $code,
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

    private function listing(array $attributes = []): Listing
    {
        return Listing::create(array_merge([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'سيارة تويوتا',
            'price_amount' => 100,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function a_price_range_in_dollars_matches_listings_priced_in_other_currencies(): void
    {
        $this->listing(['price_amount' => 100, 'currency_code' => 'USD']);   // 50,000 ر.ي
        $this->listing(['price_amount' => 50000, 'currency_code' => 'YER']); // 50,000 ر.ي
        $this->listing(['price_amount' => 376, 'currency_code' => 'SAR']);   // 50,008 ر.ي
        $this->listing(['price_amount' => 100, 'currency_code' => 'YER']);   // 100 ر.ي

        // المستخدم يبحث بين 90 و110 دولاراً
        $results = $this->search->build(new SearchCriteria(
            priceMin: 90, priceMax: 110, priceCurrency: 'USD',
        ))->get();

        $this->assertCount(3, $results, 'الثلاثة الأولى متكافئة القيمة رغم اختلاف أرقامها');
    }

    #[Test]
    public function the_same_number_in_a_different_currency_is_excluded(): void
    {
        $cheap = $this->listing(['price_amount' => 100, 'currency_code' => 'YER']);
        $this->listing(['price_amount' => 100, 'currency_code' => 'USD']);

        // نطاق بالريال اليمني: 50 إلى 150
        $results = $this->search->build(new SearchCriteria(
            priceMin: 50, priceMax: 150, priceCurrency: 'YER',
        ))->get();

        $this->assertCount(1, $results);
        $this->assertSame($cheap->id, $results->first()->id);
    }

    #[Test]
    public function listings_without_a_price_are_hidden_when_filtering_by_price(): void
    {
        $this->listing(['price_amount' => null, 'currency_code' => null, 'price_type' => 'on_request']);
        $this->listing(['price_amount' => 500, 'currency_code' => 'YER']);

        $results = $this->search->build(new SearchCriteria(
            priceMin: 100, priceMax: 1000, priceCurrency: 'YER',
        ))->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function listings_without_a_price_appear_when_not_filtering_by_price(): void
    {
        $this->listing(['price_amount' => null, 'currency_code' => null, 'price_type' => 'on_request']);
        $this->listing(['price_amount' => 500, 'currency_code' => 'YER']);

        $this->assertCount(2, $this->search->build(new SearchCriteria())->get());
    }

    #[Test]
    public function sorting_by_price_uses_the_converted_value(): void
    {
        $expensive = $this->listing(['price_amount' => 90, 'currency_code' => 'USD']);  // 45,000
        $cheap = $this->listing(['price_amount' => 100, 'currency_code' => 'YER']);     // 100

        $results = $this->search->build(new SearchCriteria(sort: 'price_asc'))->get();

        $this->assertSame($cheap->id, $results->first()->id);
        $this->assertSame($expensive->id, $results->last()->id);
    }

    #[Test]
    public function text_search_matches_across_arabic_spelling_variants(): void
    {
        $this->listing(['title' => 'شقة للإيجار في صنعاء']);
        $this->listing(['title' => 'سيارة مستعملة']);

        // المستخدم يكتب بلا همزات
        $results = $this->search->build(new SearchCriteria(query: 'شقه للايجار'))->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function text_search_ignores_diacritics_and_arabic_digits(): void
    {
        $this->listing(['title' => 'سيارة موديل ٢٠٢٠']);

        $results = $this->search->build(new SearchCriteria(query: 'موديل 2020'))->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function all_query_words_must_match(): void
    {
        $this->listing(['title' => 'شقة للإيجار', 'description' => 'في حدة']);
        $this->listing(['title' => 'سيارة للبيع', 'description' => 'موديل حديث']);

        $results = $this->search->build(new SearchCriteria(query: 'شقة حدة'))->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function the_listing_currency_filter_is_independent_of_the_price_currency(): void
    {
        $this->listing(['price_amount' => 100, 'currency_code' => 'USD']);
        $this->listing(['price_amount' => 50000, 'currency_code' => 'YER']);

        // نفس النطاق القيمي، لكن نريد الإعلانات المسعّرة بالدولار فقط
        $results = $this->search->build(new SearchCriteria(
            priceMin: 40000, priceMax: 60000, priceCurrency: 'YER',
            listingCurrency: 'USD',
        ))->get();

        $this->assertCount(1, $results);
        $this->assertSame('USD', $results->first()->currency_code);
    }

    #[Test]
    public function featured_listings_are_ordered_first(): void
    {
        $normal = $this->listing(['title' => 'إعلان عادي']);
        $featured = $this->listing(['title' => 'إعلان مميز', 'is_featured' => true]);

        $results = $this->search->build(new SearchCriteria())->get();

        $this->assertSame($featured->id, $results->first()->id);
        $this->assertSame($normal->id, $results->last()->id);
    }

    #[Test]
    public function draft_listings_never_appear_in_results(): void
    {
        $this->listing(['status' => 'draft']);
        $this->listing(['status' => 'active']);

        $this->assertCount(1, $this->search->build(new SearchCriteria())->get());
    }

    #[Test]
    public function it_reports_how_results_split_across_currencies(): void
    {
        $this->listing(['currency_code' => 'YER']);
        $this->listing(['currency_code' => 'YER']);
        $this->listing(['currency_code' => 'USD']);

        $breakdown = $this->search->currencyBreakdown(new SearchCriteria());

        $this->assertSame(2, $breakdown['YER']);
        $this->assertSame(1, $breakdown['USD']);
    }

    #[Test]
    public function an_open_ended_maximum_still_filters_the_minimum(): void
    {
        $this->listing(['price_amount' => 10, 'currency_code' => 'USD']);   // 5,000
        $this->listing(['price_amount' => 200, 'currency_code' => 'USD']);  // 100,000

        $results = $this->search->build(new SearchCriteria(
            priceMin: 50, priceMax: null, priceCurrency: 'USD',
        ))->get();

        $this->assertCount(1, $results);
    }
}