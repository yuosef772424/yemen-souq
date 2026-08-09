<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Search\Services\SearchTokenizer;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchRelevanceTest extends TestCase
{
    use RefreshDatabase;

    private SearchQueryBuilder $search;

    private SearchTokenizer $tokenizer;

    private User $user;

    private Category $category;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = app(SearchQueryBuilder::class);
        $this->tokenizer = app(SearchTokenizer::class);

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
        $this->category = Category::create(['name_ar' => 'عقارات', 'slug' => 'real-estate', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
    }

    // ---- كلمات الوقف ----

    #[Test]
    public function a_function_word_the_user_typed_no_longer_kills_the_result(): void
    {
        // العنوان لا يحتوي "في" — والمستخدم يكتبها بالسليقة
        $this->listing(['title' => 'شقة للإيجار حدة', 'description' => 'ثلاث غرف وصالة']);

        $results = $this->search->build(new SearchCriteria(query: 'شقة للايجار في حدة'))->get();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function a_query_made_only_of_function_words_does_not_return_everything(): void
    {
        $this->listing(['title' => 'سيارة تويوتا']);
        $this->listing(['title' => 'شقة في حدة']);

        // لو أُفرغت الكلمات لسقط شرط النص وعادت كل الإعلانات
        $results = $this->search->build(new SearchCriteria(query: 'هل يوجد من هذه'))->get();

        $this->assertCount(0, $results);
    }

    #[Test]
    public function commercial_intent_words_are_never_treated_as_function_words(): void
    {
        $forRent = $this->listing(['title' => 'شقة للإيجار مفروشة']);
        $this->listing(['title' => 'شقة للبيع تمليك']);

        $results = $this->search->build(new SearchCriteria(query: 'شقة للايجار'))->get();

        // حذف "للايجار" كلمة شائعة كان سيجعل الاستعلام يطابق الاثنين
        $this->assertCount(1, $results);
        $this->assertSame($forRent->id, $results->first()->id);
    }

    #[Test]
    public function stop_words_are_kept_in_the_stored_text(): void
    {
        $listing = $this->listing(['title' => 'شقة للإيجار في حدة']);

        // النص المخزَّن كامل — الحذف قرار خاص بالاستعلام لا بالتخزين
        $this->assertStringContainsString('في', $listing->title_normalized);
    }

    #[Test]
    public function a_long_query_is_capped(): void
    {
        $long = 'سيارة تويوتا كورولا موديل حديث نظيفة مكيف فتحة جلد شاشة كاميرا حساسات';

        $this->assertLessThanOrEqual(8, count($this->tokenizer->tokens($long)));
    }

    // ---- إشارات الصلة ----

    #[Test]
    public function relevance_beats_recency(): void
    {
        // الأقدم عبارةً كاملة، والأحدث مطابقة في الوصف فقط
        $phraseInTitle = $this->listing([
            'title' => 'شقة تويوتا كورولا للبيع',
            'published_at' => now()->subDays(30),
        ]);

        $tokensInTitle = $this->listing([
            'title' => 'كورولا موديل تويوتا',
            'published_at' => now()->subDays(15),
        ]);

        $inDescription = $this->listing([
            'title' => 'سيارة نظيفة جداً',
            'description' => 'النوع تويوتا كورولا بحالة ممتازة',
            'published_at' => now(),
        ]);

        $ids = $this->search->build(new SearchCriteria(query: 'تويوتا كورولا'))->get()->pluck('id')->all();

        $this->assertSame(
            [$phraseInTitle->id, $tokensInTitle->id, $inDescription->id],
            $ids
        );
    }

    #[Test]
    public function more_query_words_in_the_title_ranks_higher(): void
    {
        // لا عنوان يحوي الكلمات الثلاث — الشرط القديم كان يسوّي بين الثلاثة.
        // ولا بد أن تظهر 2015 في نص كل إعلان، وإلا استُبعد بالمطابقة
        // قبل أن يصل إلى الترتيب أصلاً.
        $twoWords = $this->listing([
            'title' => 'تويوتا كورولا نظيفة',
            'description' => 'موديل 2015 بحالة ممتازة',
            'published_at' => now()->subDays(30),
        ]);

        $oneWord = $this->listing([
            'title' => 'كورولا مستعملة',
            'description' => 'ماركة تويوتا موديل 2015',
            'published_at' => now()->subDays(15),
        ]);

        $noneInTitle = $this->listing([
            'title' => 'سيارة بحالة جيدة',
            'description' => 'تويوتا كورولا موديل 2015',
            'published_at' => now(),
        ]);

        $ids = $this->search
            ->build(new SearchCriteria(query: 'تويوتا كورولا 2015'))
            ->get()->pluck('id')->all();

        $this->assertSame([$twoWords->id, $oneWord->id, $noneInTitle->id], $ids);
    }

    #[Test]
    public function a_single_word_query_still_prefers_the_title(): void
    {
        $inDescription = $this->listing([
            'title' => 'سيارة نظيفة',
            'description' => 'النوع كورولا',
            'published_at' => now(),
        ]);

        $inTitle = $this->listing([
            'title' => 'كورولا مستعملة',
            'published_at' => now()->subDays(20),
        ]);

        $ids = $this->search->build(new SearchCriteria(query: 'كورولا'))->get()->pluck('id')->all();

        $this->assertSame([$inTitle->id, $inDescription->id], $ids);
    }

    #[Test]
    public function an_explicit_price_sort_is_not_overridden_by_relevance(): void
    {
        $expensiveButRelevant = $this->listing([
            'title' => 'تويوتا كورولا وكالة',
            'price_amount' => 900000,
        ]);

        $cheapAndLessRelevant = $this->listing([
            'title' => 'سيارة قديمة',
            'description' => 'تويوتا كورولا للقطع',
            'price_amount' => 50000,
        ]);

        $ids = $this->search
            ->build(new SearchCriteria(query: 'تويوتا كورولا', sort: 'price_asc'))
            ->get()->pluck('id')->all();

        // من طلب الأرخص أولاً يريد عمود سعر مرتّباً، لا مستويات صلة
        $this->assertSame([$cheapAndLessRelevant->id, $expensiveButRelevant->id], $ids);
    }

    #[Test]
    public function a_live_promotion_still_outranks_relevance(): void
    {
        $featured = $this->listing([
            'title' => 'سيارة للبيع',
            'description' => 'تويوتا كورولا',
            'is_featured' => true,
            'featured_until' => now()->addWeek(),
            'published_at' => now()->subMonth(),
        ]);

        $this->listing([
            'title' => 'تويوتا كورولا وكالة',
            'published_at' => now(),
        ]);

        $ids = $this->search->build(new SearchCriteria(query: 'تويوتا كورولا'))->get()->pluck('id')->all();

        $this->assertSame($featured->id, $ids[0]);
    }

    #[Test]
    public function the_currency_breakdown_survives_the_relevance_ordering(): void
    {
        $this->listing(['title' => 'تويوتا كورولا']);

        // GROUP BY لا يقبل ترتيباً بتعبير على عمود غير مجمَّع — reorder() تحميه
        $breakdown = $this->search->currencyBreakdown(new SearchCriteria(query: 'تويوتا كورولا'));

        $this->assertSame(['YER' => 1], $breakdown);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::create(array_merge([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'إعلان تجريبي',
            'description' => 'وصف قصير.',
            'price_amount' => 100000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ], $attributes));
    }
}