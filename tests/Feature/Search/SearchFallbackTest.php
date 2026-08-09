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

/**
 * الدرجتان: التامة ثم الأقرب.
 *
 * أهم اختبار هنا هو الثاني: الاشتراط الصارم كان يُرجع صفراً لاستعلام
 * واقعي تماماً («كورولا 2018 اوتوماتيك») لأن كلمة واحدة لم يكتبها
 * المعلن — والمستخدم يقرأ الصفر كغياب السلعة من المنصة، لا كقصور في
 * المطابقة. هذا أغلى عيب يمكن أن يحمله محرك بحث.
 */
class SearchFallbackTest extends TestCase
{
    use RefreshDatabase;

    private SearchQueryBuilder $search;

    private User $user;

    private Category $category;

    private Location $location;

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

        $this->user = User::factory()->create();
        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);

        $this->search = app(SearchQueryBuilder::class);
    }

    private function listing(string $title, ?string $description = null): Listing
    {
        return Listing::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => $title,
            'description' => $description,
            'price_amount' => 1000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);
    }

    #[Test]
    public function a_full_match_reports_the_exact_mode(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $result = $this->search->search(new SearchCriteria(query: 'كورولا 2018'));

        $this->assertSame('exact', $result['mode']);
        $this->assertSame(1, $result['listings']->total());
    }

    /** كلمة واحدة زائدة لم يكتبها المعلن لا يجوز أن تُفرغ الصفحة */
    #[Test]
    public function one_missing_word_falls_back_to_the_nearest_results(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $result = $this->search->search(new SearchCriteria(query: 'كورولا 2018 اوتوماتيك'));

        $this->assertSame('near', $result['mode']);
        $this->assertSame(1, $result['listings']->total());
    }

    #[Test]
    public function the_nearest_results_are_ordered_by_how_many_words_matched(): void
    {
        $two = $this->listing('تويوتا كورولا موديل 2018');
        $one = $this->listing('نيسان صني موديل 2018');

        $result = $this->search->search(new SearchCriteria(query: 'كورولا 2018 اوتوماتيك'));

        $ids = $result['listings']->pluck('id')->all();

        $this->assertSame('near', $result['mode']);
        $this->assertSame([$two->id, $one->id], $ids);
    }

    /**
     * التراجع لا يعمل إلا عند الحاجة.
     *
     * لو عمل دائماً لظهرت كل إعلانات «موديل» فوق النتائج الصحيحة —
     * وتغطية زائدة لا يريدها المستخدم أسوأ من نتائج أقل.
     */
    #[Test]
    public function the_fallback_does_not_run_when_the_strict_pass_succeeds(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');
        $this->listing('نيسان صني موديل 2005');

        $result = $this->search->search(new SearchCriteria(query: 'كورولا 2018'));

        $this->assertSame('exact', $result['mode']);
        $this->assertSame(1, $result['listings']->total());
    }

    #[Test]
    public function nothing_at_all_reports_the_none_mode(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $result = $this->search->search(new SearchCriteria(query: 'غواصة نووية'));

        $this->assertSame('none', $result['mode']);
        $this->assertSame(0, $result['listings']->total());
    }

    #[Test]
    public function browsing_without_a_query_is_not_a_fallback(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $this->assertSame('browse', $this->search->search(new SearchCriteria)['mode']);
    }

    /** الفلاتر تبقى ملزمة في الدرجة الثانية — التراجع في النص لا في الشروط */
    #[Test]
    public function the_fallback_still_respects_the_price_filter(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $result = $this->search->search(new SearchCriteria(
            query: 'كورولا 2018 اوتوماتيك',
            priceMin: 900000,
        ));

        $this->assertSame(0, $result['listings']->total());
    }

    #[Test]
    public function the_page_tells_the_user_when_it_fell_back(): void
    {
        $this->listing('تويوتا كورولا موديل 2018');

        $this->get('/?q=' . urlencode('كورولا 2018 اوتوماتيك'))
            ->assertOk()
            ->assertSee('أقرب النتائج');
    }
}