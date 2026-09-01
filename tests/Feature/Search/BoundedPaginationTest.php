<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Pagination\BoundedPaginator;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الترقيم المسقوف.
 *
 * ما يحرسه: أن العدّ لا يتجاوز السقف مهما اتّسعت المطابقة، وأن ما يُعرض
 * على الشاشة يبقى صادقاً عند بلوغه — «أكثر من» لا رقمٌ لم يُحسب.
 *
 * والسقف يُخفَّض هنا إلى ثلاثة: السلوك هو المقصود، وبناء ألف صفٍّ لإثباته
 * يقيس بطء الاختبار لا صحة الكود.
 */
class BoundedPaginationTest extends TestCase
{
    use RefreshDatabase;

    private SearchQueryBuilder $search;

    private User $user;

    private Category $category;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        config(['search.result_cap' => 3]);

        $this->search = app(SearchQueryBuilder::class);

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

    private function listings(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->listing(['title' => "سيارة تويوتا رقم {$i}"]);
        }
    }

    #[Test]
    public function the_count_stops_at_the_cap_however_many_match(): void
    {
        $this->listings(7);

        $listings = $this->search->paginate(new SearchCriteria(query: 'سيارة', perPage: 2));

        $this->assertSame(3, $listings->total(), 'العدّ يتوقف عند السقف لا عند آخر مطابقة');
        $this->assertTrue($listings->isCapped());
    }

    #[Test]
    public function a_result_set_that_fits_under_the_cap_keeps_its_exact_count(): void
    {
        $this->listings(3);

        $listings = $this->search->paginate(new SearchCriteria(query: 'سيارة', perPage: 2));

        $this->assertSame(3, $listings->total());
        $this->assertFalse($listings->isCapped(), 'ثلاثةٌ بالضبط ليست «أكثر من ثلاثة»');
    }

    /**
     * الصفّ الزائد الذي يُعدّ (cap + 1) هو كل ما يفصل الحالتين أعلاه —
     * وهذا الاختبار يثبّت الحدّ نفسه لأنه أسهل ما يُكسر بتعديل لاحق.
     */
    #[Test]
    public function one_row_past_the_cap_is_what_marks_it_capped(): void
    {
        $this->listings(4);

        $listings = $this->search->paginate(new SearchCriteria(query: 'سيارة', perPage: 2));

        $this->assertSame(3, $listings->total());
        $this->assertTrue($listings->isCapped());
    }

    /**
     * ما وراء السقف لا يُستعلَم عنه أصلاً.
     *
     * `OFFSET` كبير يجعل قاعدة البيانات تمرّ على كل صفٍّ تتخطّاه — وهو
     * الثمن الذي يُدفع لصفحةٍ لا يفتحها أحد. والحارس هنا على عدد
     * الاستعلامات لا على النتيجة: النتيجة فارغة في الحالتين.
     */
    #[Test]
    public function a_page_beyond_the_cap_runs_no_page_query(): void
    {
        $this->listings(7);

        Paginator::currentPageResolver(fn () => 50);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $listings = $this->search->paginate(new SearchCriteria(query: 'سيارة', perPage: 2));

        $this->assertTrue($listings->isEmpty());
        $this->assertEmpty(
            array_filter($statements, fn (string $sql): bool => str_contains($sql, 'offset')),
            'لا استعلام بإزاحة: الصفحة خارج المدى لا تُطلَب من قاعدة البيانات أصلاً'
        );
    }

    /**
     * التوزيع من نفس النافذة التي جاء منها العدّاد.
     *
     * لو حُسب على المطابقات كلها لظهر على الشاشة عدّادٌ يقول «أكثر من
     * ثلاثة» وتحته توزيعٌ يجمع سبعة — والمستخدم يقرأ الرقمين معاً.
     */
    #[Test]
    public function the_currency_breakdown_is_bounded_by_the_same_cap(): void
    {
        $this->listings(4);
        $this->listing(['title' => 'سيارة تويوتا بالدولار', 'currency_code' => 'USD']);
        $this->listing(['title' => 'سيارة نيسان بالدولار', 'currency_code' => 'USD']);

        $breakdown = $this->search->currencyBreakdown(new SearchCriteria(query: 'سيارة'));

        $this->assertSame(3, array_sum($breakdown), 'مجموع التوزيع لا يتجاوز السقف');
    }

    /** العدّ الخام يبقى متاحاً للتشخيص — السقف على مسار الطلب وحده */
    #[Test]
    public function the_raw_count_stays_available_for_diagnostics(): void
    {
        $this->listings(7);

        $this->assertSame(7, $this->search->count(new SearchCriteria(query: 'سيارة')));
    }

    #[Test]
    public function the_results_page_says_more_than_instead_of_a_number_it_did_not_count(): void
    {
        $this->listings(7);

        $this->get('/?q='.urlencode('سيارة'))
            ->assertOk()
            ->assertSee('أكثر من', false)
            ->assertSee('(3+)', false);
    }

    #[Test]
    public function the_api_declares_that_its_total_is_capped(): void
    {
        $this->listings(7);

        $this->getJson('/api/v1/listings?q='.urlencode('سيارة'))
            ->assertOk()
            ->assertJsonPath('meta.results_capped', true)
            ->assertJsonPath('meta.total', 3);
    }

    #[Test]
    public function the_paginator_the_engine_returns_is_the_bounded_one(): void
    {
        $this->listings(1);

        $result = $this->search->search(new SearchCriteria(query: 'سيارة'));

        $this->assertInstanceOf(BoundedPaginator::class, $result['listings']);
    }
}
