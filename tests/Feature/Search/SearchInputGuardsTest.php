<?php

namespace Tests\Feature\Search;

use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * حراسة المدخلات على مستوى النوع.
 *
 * القائمة البيضاء القائمة تحمي *القيم*؛ هذه الاختبارات تحمي *الأنواع*.
 * الفرق ليس نظرياً: ?q[]=x كان يُسقط الصفحة الرئيسية بخطأ 500 لأن
 * Stringable يحوّل المصفوفة بـ(string)، وPHP يُطلق تحذيراً، ولارافل
 * يحوّل التحذير إلى استثناء. رابط من سطر واحد يُسقط أهم صفحة في الموقع.
 */
class SearchInputGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'YER',
            'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true,
        ]);

        ExchangeRate::create([
            'currency_code' => 'YER', 'rate_to_base' => 1,
            'effective_date' => now()->subYear()->toDateString(),
            'source' => 'test', 'created_at' => now(),
        ]);
    }

    #[Test]
    public function an_array_query_does_not_bring_down_the_page(): void
    {
        $this->get('/?q[]=%D8%B4%D9%82%D8%A9')->assertOk();
    }

    #[Test]
    public function an_array_in_any_filter_is_ignored_rather_than_fatal(): void
    {
        $this->get('/?sort[]=newest&currency[]=YER&condition[]=new&min[]=5&category[]=1')
            ->assertOk();
    }

    #[Test]
    public function an_array_query_is_read_as_no_query(): void
    {
        $criteria = SearchCriteria::fromRequest(request()->merge(['q' => ['شقة']]));

        $this->assertFalse($criteria->hasQuery());
    }

    #[Test]
    public function a_very_long_query_is_truncated_before_it_reaches_the_database(): void
    {
        $criteria = SearchCriteria::fromRequest(request()->merge([
            'q' => str_repeat('سيارة ', 500),
        ]));

        $this->assertLessThanOrEqual(
            SearchCriteria::MAX_QUERY_LENGTH,
            mb_strlen((string) $criteria->query)
        );
    }

    /** «من abc» كان يصبح «من صفر» — حدّ لم يطلبه أحد */
    #[Test]
    public function a_non_numeric_price_bound_is_dropped_not_read_as_zero(): void
    {
        $criteria = SearchCriteria::fromRequest(request()->merge(['min' => 'abc']));

        $this->assertNull($criteria->priceMin);
        $this->assertFalse($criteria->hasPriceFilter());
    }

    #[Test]
    public function a_negative_bound_is_still_ignored(): void
    {
        $criteria = SearchCriteria::fromRequest(request()->merge(['min' => '-50', 'max' => '100']));

        $this->assertNull($criteria->priceMin);
        $this->assertSame(100.0, $criteria->priceMax);
    }

    #[Test]
    public function per_page_stays_inside_its_bounds(): void
    {
        $this->assertSame(60, SearchCriteria::fromRequest(request()->merge(['per_page' => '5000']))->perPage);
        $this->assertSame(6, SearchCriteria::fromRequest(request()->merge(['per_page' => '1']))->perPage);
        $this->assertSame(24, SearchCriteria::fromRequest(request()->merge(['per_page' => 'abc']))->perPage);
    }
}