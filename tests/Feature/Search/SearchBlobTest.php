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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * المدينة والتصنيف صارا نصاً قابلاً للبحث.
 * قبل هذا كان «شقة في حدة» يُرجع صفراً دائماً، لأن الموقع مفتاح أجنبي.
 */
class SearchBlobTest extends TestCase
{
    use RefreshDatabase;

    private SearchQueryBuilder $search;

    private User $user;

    private Category $cars;

    private Category $realEstate;

    private Location $sanaa;

    private Location $hadda;

    private Location $aden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = app(SearchQueryBuilder::class);

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

        $this->cars = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->realEstate = Category::create(['name_ar' => 'عقارات', 'slug' => 'real-estate', 'path' => '']);

        $this->sanaa = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
        $this->hadda = Location::create([
            'name_ar' => 'حدة', 'slug' => 'hadda', 'level' => 'district',
            'parent_id' => $this->sanaa->id, 'path' => '',
        ]);
        $this->aden = Location::create([
            'name_ar' => 'عدن', 'slug' => 'aden', 'level' => 'governorate', 'path' => '',
        ]);
    }

    #[Test]
    public function a_district_name_is_searchable_text(): void
    {
        $inHadda = $this->listing([
            'title' => 'شقة ثلاث غرف',
            'location_id' => $this->hadda->id,
            'category_id' => $this->realEstate->id,
        ]);

        $this->listing([
            'title' => 'شقة ثلاث غرف',
            'location_id' => $this->aden->id,
            'category_id' => $this->realEstate->id,
        ]);

        $results = $this->search->build(new SearchCriteria(query: 'شقة في حدة'))->get();

        $this->assertCount(1, $results);
        $this->assertSame($inHadda->id, $results->first()->id);
    }

    #[Test]
    public function the_governorate_is_searchable_from_a_district_listing(): void
    {
        // من يكتب «صنعاء» يقصد كل مديرياتها، والإعلان مسجّل في حدة
        $this->listing([
            'title' => 'شقة ثلاث غرف',
            'location_id' => $this->hadda->id,
        ]);

        $this->assertCount(1, $this->search->build(new SearchCriteria(query: 'شقة صنعاء'))->get());
    }

    #[Test]
    public function a_category_name_is_searchable_text(): void
    {
        $this->listing([
            'title' => 'كورولا موديل 2018',
            'category_id' => $this->cars->id,
        ]);

        $this->assertCount(1, $this->search->build(new SearchCriteria(query: 'سيارات كورولا'))->get());
    }

    #[Test]
    public function moving_a_listing_to_another_district_updates_its_blob(): void
    {
        $listing = $this->listing([
            'title' => 'شقة ثلاث غرف',
            'location_id' => $this->hadda->id,
        ]);

        $listing->update(['location_id' => $this->aden->id]);

        // لو لم يُعَد البناء لبقي الإعلان قابلاً للعثور عليه بالمدينة الخطأ
        $this->assertCount(0, $this->search->build(new SearchCriteria(query: 'شقة حدة'))->get());
        $this->assertCount(1, $this->search->build(new SearchCriteria(query: 'شقة عدن'))->get());
    }

    #[Test]
    public function a_title_match_still_outranks_a_city_match(): void
    {
        $inTitle = $this->listing([
            'title' => 'سيارة كورولا وكالة',
            'location_id' => $this->aden->id,
            'published_at' => now()->subMonth(),
        ]);

        $viaCityOnly = $this->listing([
            'title' => 'سيارة نظيفة',
            'description' => 'كورولا بحالة ممتازة',
            'location_id' => $this->aden->id,
            'published_at' => now(),
        ]);

        $ids = $this->search->build(new SearchCriteria(query: 'كورولا عدن'))->get()->pluck('id')->all();

        $this->assertSame([$inTitle->id, $viaCityOnly->id], $ids);
    }

    #[Test]
    public function the_rebuild_command_backfills_an_empty_blob(): void
    {
        $listing = $this->listing([
            'title' => 'شقة ثلاث غرف',
            'location_id' => $this->hadda->id,
        ]);

        // محاكاة إعلان أُنشئ قبل الهجرة
        DB::table('listings')->where('id', $listing->id)->update(['search_blob' => null]);

        $this->assertCount(0, $this->search->build(new SearchCriteria(query: 'شقة حدة'))->get());

        $this->artisan('catalog:rebuild-search-blob')->assertSuccessful();

        $this->assertCount(1, $this->search->build(new SearchCriteria(query: 'شقة حدة'))->get());
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::create(array_merge([
            'user_id' => $this->user->id,
            'category_id' => $this->realEstate->id,
            'location_id' => $this->sanaa->id,
            'title' => 'إعلان تجريبي',
            'description' => 'وصف قصير.',
            'price_amount' => 100000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ], $attributes));
    }
}