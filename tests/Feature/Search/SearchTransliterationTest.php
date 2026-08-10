<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\Database\Seeders\SynonymSeeder;
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
 * النقل الحرفي من طرف إلى طرف.
 *
 * الاختبار الأول والثاني هما المقصود كله: عنوان مكتوب باللاتينية يُعثر
 * عليه ببحث عربي، وعنوان مكتوب بالعربية يُعثر عليه ببحث لاتيني. بلا
 * ذلك، سوق الجوالات في اليمن — حيث تُكتب نفس السلعة بالصورتين — نصفه
 * غير قابل للعثور عليه.
 */
class SearchTransliterationTest extends TestCase
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
        $this->category = Category::create(['name_ar' => 'جوالات', 'slug' => 'phones', 'path' => '']);
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
            'price_amount' => 150000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);
    }

    /** @return array<int, int> */
    private function idsFor(string $query): array
    {
        return $this->search->build(new SearchCriteria(query: $query))->get()->pluck('id')->all();
    }

    #[Test]
    public function an_arabic_query_finds_a_latin_model_code(): void
    {
        $listing = $this->listing('Samsung Galaxy S23 جديد');

        $this->assertSame([$listing->id], $this->idsFor('اس23'));
    }

    #[Test]
    public function a_latin_query_finds_an_arabic_model_code(): void
    {
        $listing = $this->listing('سامسونج جالكسي اس22 مستعمل');

        $this->assertSame([$listing->id], $this->idsFor('s22'));
    }

    #[Test]
    public function the_space_in_the_title_does_not_hide_it(): void
    {
        $listing = $this->listing('سامسونج جالكسي اس 22');

        $this->assertSame([$listing->id], $this->idsFor('اس22'));
        $this->assertSame([$listing->id], $this->idsFor('s22'));
    }

    #[Test]
    public function a_brand_name_crosses_scripts(): void
    {
        $listing = $this->listing('iPhone 13 Pro Max للبيع');

        $this->assertSame([$listing->id], $this->idsFor('ايفون'));
    }

    /**
     * الدقة لا تُدفع ثمناً للتغطية: s22 لا يجوز أن تجلب a22.
     *
     * هذا هو الخطر الحقيقي في توسيع المطابقة — أن يصبح كل شيء يطابق كل
     * شيء. الاختبار يحرس الحدّ.
     */
    #[Test]
    public function a_model_code_does_not_match_a_different_one(): void
    {
        $s22 = $this->listing('سامسونج جالكسي اس22');
        $this->listing('سامسونج جالكسي ايه22');

        $this->assertSame([$s22->id], $this->idsFor('s22'));
    }

    #[Test]
    public function arabic_digits_in_the_query_work_too(): void
    {
        $listing = $this->listing('Samsung Galaxy S23 جديد');

        $this->assertSame([$listing->id], $this->idsFor('اس٢٣'));
    }

    /**
     * العمود يُبنى من العنوان لا من الوصف.
     *
     * الوصف نص حر مليء بأرقام (سعر، سنة، مسافة، هاتف)، فاشتقاق الصور منه
     * يولّد كلمات لا يقصدها أحد. من يريد أن يُعثَر عليه بكود الموديل
     * يكتبه في العنوان.
     */
    #[Test]
    public function variants_come_from_the_title_not_the_description(): void
    {
        Listing::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'جوال سامسونج نظيف',
            'description' => 'الموديل اس22 والحالة ممتازة',
            'price_amount' => 150000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);

        $this->assertSame([], $this->idsFor('s22'));
    }
}