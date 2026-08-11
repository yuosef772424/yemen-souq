<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\Database\Seeders\SynonymSeeder;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Search\Services\SynonymExpander;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * المكافئات — «المستوى الثاني» من الترتيب المطلوب.
 *
 * جدول search_synonyms كان في المخطط من أول هجرة ولا سطر يقرأه. أثره
 * الحقيقي في اليمن: من يكتب «موتر» أو «دباب» بلهجته كان يُعاقَب بصفر
 * نتائج، ومن يكتب بالفصحى يجد. محرك بحث يفضّل لهجةً على أخرى في سوق
 * محلي عيب في المنتج لا في البيانات.
 */
class SearchSynonymTest extends TestCase
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

        (new SynonymSeeder)->run();

        $this->search = app(SearchQueryBuilder::class);
    }

    private function listing(string $title): Listing
    {
        return Listing::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => $title,
            'price_amount' => 1000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);
    }

    #[Test]
    public function a_dialect_word_finds_the_formal_one(): void
    {
        $this->listing('سيارة تويوتا كورولا للبيع');

        $results = $this->search->build(new SearchCriteria(query: 'موتر تويوتا'))->get();

        $this->assertCount(1, $results);
        $this->assertSame('exact', $this->search->search(new SearchCriteria(query: 'موتر تويوتا'))['mode']);
    }

    /** العلاقة متبادلة: صف واحد يخدم الاتجاهين */
    #[Test]
    public function the_formal_word_finds_the_dialect_one(): void
    {
        $this->listing('عربية هيونداي النترا');

        $this->assertCount(1, $this->search->build(new SearchCriteria(query: 'سيارة النترا'))->get());
    }

    #[Test]
    public function the_phone_family_is_interchangeable(): void
    {
        $this->listing('موبايل سامسونج جالكسي');

        foreach (['جوال سامسونج', 'تلفون سامسونج', 'هاتف سامسونج'] as $query) {
            $this->assertCount(1, $this->search->build(new SearchCriteria(query: $query))->get(), $query);
        }
    }

    /**
     * الحدّ الذي لا يُعبَر.
     *
     * «للبيع» و«للإيجار» ليسا مكافئين، وإدخالهما زوجاً يجعل من يبحث عن
     * شقة للإيجار يرى شققاً للبيع — وهذا في العقار ليس ضجيجاً بل خطأ
     * يهدر وقت الطرفين. الاختبار يحرس القاعدة لا التنفيذ.
     */
    #[Test]
    public function opposites_are_never_treated_as_equivalents(): void
    {
        $this->assertSame(0, DB::table('search_synonyms')
            ->whereIn('term', ['للبيع', 'بيع'])
            ->whereIn('synonym', ['للايجار', 'ايجار'])
            ->count());

        $this->listing('شقة للإيجار في صنعاء');

        $this->assertCount(0, $this->search->build(new SearchCriteria(query: 'شقة للبيع'))->get());
    }

    #[Test]
    public function the_stored_terms_are_normalized(): void
    {
        foreach (DB::table('search_synonyms')->pluck('term') as $term) {
            $this->assertSame(
                $term,
                app(\App\Modules\Shared\Services\ArabicNormalizer::class)->normalize($term)
            );
        }
    }

    /** سقف المكافئات لكل كلمة — وإلا صار الاستعلام أربعين شرط LIKE */
    #[Test]
    public function the_number_of_variants_per_word_is_capped(): void
    {
        $groups = app(SynonymExpander::class)->expand(['جوال']);

        $this->assertLessThanOrEqual(SynonymExpander::MAX_PER_TOKEN + 1, count($groups[0]));
    }

    #[Test]
    public function a_word_without_synonyms_expands_to_itself_only(): void
    {
        $this->assertSame([['مسمار']], app(SynonymExpander::class)->expand(['مسمار']));
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $before = DB::table('search_synonyms')->count();

        (new SynonymSeeder)->run();

        $this->assertSame($before, DB::table('search_synonyms')->count());
    }
}