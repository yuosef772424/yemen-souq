<?php

namespace Tests\Feature\Search;

use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Shared\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * العيب: كل قيمة في الرابط كانت تُقبل كما هي.
 * ?currency=EUR كان يصل إلى ExchangeRateService فيرمي استثناءً — خطأ 500
 * على صفحة عامة يستطيع أي أحد إحداثه.
 */
class SearchCriteriaGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::flushCache();
        Currency::query()->delete();
        Currency::create(['code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'Yemeni Rial', 'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true, 'is_active' => true, 'sort_order' => 1]);
        Currency::create(['code' => 'USD', 'name_ar' => 'دولار', 'name_en' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_base' => false, 'is_active' => true, 'sort_order' => 3]);
        Currency::flushCache();
    }

    #[Test]
    public function an_unknown_price_currency_falls_back_to_the_base_currency(): void
    {
        $this->assertSame('YER', $this->criteria('currency=EUR')->priceCurrency);
        $this->assertSame('YER', $this->criteria('currency=<script>')->priceCurrency);
        $this->assertSame('USD', $this->criteria('currency=usd')->priceCurrency);
    }

    #[Test]
    public function an_unknown_listing_currency_is_dropped_rather_than_returning_nothing(): void
    {
        $this->assertNull($this->criteria('listing_currency=EUR')->listingCurrency);
        $this->assertSame('USD', $this->criteria('listing_currency=USD')->listingCurrency);
    }

    #[Test]
    public function an_inverted_range_is_reordered_instead_of_matching_nothing(): void
    {
        $criteria = $this->criteria('min=500&max=100');

        $this->assertSame(100.0, $criteria->priceMin);
        $this->assertSame(500.0, $criteria->priceMax);
    }

    #[Test]
    public function a_negative_bound_is_ignored(): void
    {
        $criteria = $this->criteria('min=-50&max=300');

        $this->assertNull($criteria->priceMin);
        $this->assertSame(300.0, $criteria->priceMax);
    }

    #[Test]
    public function an_unknown_sort_falls_back_to_newest(): void
    {
        $this->assertSame('newest', $this->criteria('sort=price_asc;drop')->sort);
        $this->assertSame('price_asc', $this->criteria('sort=price_asc')->sort);
    }

    #[Test]
    public function unknown_condition_and_sale_type_are_dropped(): void
    {
        $criteria = $this->criteria('condition=broken&type=barter');

        $this->assertNull($criteria->condition);
        $this->assertNull($criteria->saleType);
    }

    private function criteria(string $queryString): SearchCriteria
    {
        return SearchCriteria::fromRequest(Request::create('/search?' . $queryString));
    }
}