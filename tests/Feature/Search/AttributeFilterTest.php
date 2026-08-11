<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Shared\Models\Attribute;
use App\Modules\Shared\Models\AttributeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * الفلترة بخصائص التصنيف.
 *
 * هذه هي الغاية التي فُصل value_number لأجلها عن value_string في
 * السكيمة — «منفصل لتمكين الفلترة الرقمية» بنصّ تعليق الهجرة. كان
 * العمود يُكتب ولا يُقرأ، فبقي السبب معطَّلاً.
 *
 * والحالات العدائية هنا ليست زينة: SearchCriteria مبنيّ كله على أن
 * الرابط يكتبه أي أحد، وأن مدخلاً غريباً يجب أن يوسّع البحث لا أن
 * يُسقط الصفحة.
 */
class AttributeFilterTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private Attribute $mileage;

    private Attribute $transmission;

    private Attribute $warranty;

    private AttributeOption $automatic;

    private AttributeOption $manual;

    private Listing $low;

    private Listing $high;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->mileage = Attribute::create([
            'attr_key' => 'mileage', 'name_ar' => 'الممشى', 'data_type' => 'number', 'unit' => 'كم',
        ]);

        $this->transmission = Attribute::create([
            'attr_key' => 'transmission', 'name_ar' => 'ناقل الحركة', 'data_type' => 'option',
        ]);

        $this->warranty = Attribute::create([
            'attr_key' => 'warranty', 'name_ar' => 'ضمان', 'data_type' => 'boolean',
        ]);

        $this->automatic = AttributeOption::create([
            'attribute_id' => $this->transmission->id,
            'value_ar' => 'أوتوماتيك', 'value_normalized' => 'اوتوماتيك', 'sort_order' => 1,
        ]);

        $this->manual = AttributeOption::create([
            'attribute_id' => $this->transmission->id,
            'value_ar' => 'عادي', 'value_normalized' => 'عادي', 'sort_order' => 2,
        ]);

        $this->category->attributes()->sync([
            $this->mileage->id => ['is_filterable' => true, 'sort_order' => 1],
            $this->transmission->id => ['is_filterable' => true, 'sort_order' => 2],
            $this->warranty->id => ['is_filterable' => true, 'sort_order' => 3],
        ]);

        $owner = User::factory()->create();

        $this->low = $this->listingFor($owner, ['title' => 'سيارة قليلة الممشى جداً']);
        $this->high = $this->listingFor($owner, ['title' => 'سيارة كثيرة الممشى جداً']);

        $this->setValue($this->low, $this->mileage, ['value_number' => 40000]);
        $this->setValue($this->high, $this->mileage, ['value_number' => 250000]);

        $this->setValue($this->low, $this->transmission, ['option_id' => $this->automatic->id]);
        $this->setValue($this->high, $this->transmission, ['option_id' => $this->manual->id]);

        $this->setValue($this->low, $this->warranty, ['value_boolean' => true]);
        $this->setValue($this->high, $this->warranty, ['value_boolean' => false]);
    }

    private function setValue(Listing $listing, Attribute $attribute, array $columns): void
    {
        DB::table('listing_attribute_values')->insert([
            'listing_id' => $listing->id,
            'attribute_id' => $attribute->id,
            'value_string' => null,
            'value_number' => null,
            'value_boolean' => null,
            'value_date' => null,
            'option_id' => null,
            ...$columns,
        ]);
    }

    /** @return list<int> معرّفات النتائج */
    private function searchWith(array $query): array
    {
        $criteria = SearchCriteria::fromRequest(Request::create('/', 'GET', $query));

        return app(SearchQueryBuilder::class)
            ->build($criteria)
            ->pluck('listings.id')
            ->all();
    }

    #[Test]
    public function a_numeric_ceiling_excludes_the_higher_listing(): void
    {
        $results = $this->searchWith(['attr' => ['mileage' => ['max' => 100000]]]);

        $this->assertSame([$this->low->id], $results);
    }

    #[Test]
    public function a_numeric_floor_excludes_the_lower_listing(): void
    {
        $results = $this->searchWith(['attr' => ['mileage' => ['min' => 100000]]]);

        $this->assertSame([$this->high->id], $results);
    }

    #[Test]
    public function a_range_keeps_only_what_falls_inside_it(): void
    {
        $this->assertSame(
            [$this->low->id],
            $this->searchWith(['attr' => ['mileage' => ['min' => 10000, 'max' => 100000]]])
        );

        $this->assertSame(
            [],
            $this->searchWith(['attr' => ['mileage' => ['min' => 300000, 'max' => 400000]]])
        );
    }

    /** الحدّان المنقلبان يُعكسان بدل إرجاع صفر نتائج — كالنطاق السعري */
    #[Test]
    public function reversed_bounds_are_swapped(): void
    {
        $this->assertSame(
            [$this->low->id],
            $this->searchWith(['attr' => ['mileage' => ['min' => 100000, 'max' => 10000]]])
        );
    }

    #[Test]
    public function an_option_filter_matches_that_option_only(): void
    {
        $this->assertSame(
            [$this->low->id],
            $this->searchWith(['attr' => ['transmission' => $this->automatic->id]])
        );

        $this->assertSame(
            [$this->high->id],
            $this->searchWith(['attr' => ['transmission' => $this->manual->id]])
        );
    }

    #[Test]
    public function a_boolean_filter_reads_zero_as_no(): void
    {
        $this->assertSame([$this->low->id], $this->searchWith(['attr' => ['warranty' => 1]]));
        $this->assertSame([$this->high->id], $this->searchWith(['attr' => ['warranty' => 0]]));
    }

    /**
     * فلتران يتقاطعان لا يتحدان.
     *
     * وهذه الحالة تحديداً هي ما يبرّر EXISTS: الإعلان له صفّان في جدول
     * القيم، وJOIN عليهما يضاعف صفوف النتيجة.
     */
    #[Test]
    public function two_filters_are_combined_with_and(): void
    {
        $this->assertSame([$this->low->id], $this->searchWith([
            'attr' => [
                'mileage' => ['max' => 100000],
                'transmission' => $this->automatic->id,
            ],
        ]));

        // ممشى منخفض لكن ناقل عادي — لا إعلان يجمعهما
        $this->assertSame([], $this->searchWith([
            'attr' => [
                'mileage' => ['max' => 100000],
                'transmission' => $this->manual->id,
            ],
        ]));
    }

    #[Test]
    public function a_listing_without_the_attribute_is_excluded(): void
    {
        $bare = $this->listingFor(User::factory()->create(), ['title' => 'إعلان بلا مواصفات مذكورة']);

        $results = $this->searchWith(['attr' => ['mileage' => ['max' => 999999]]]);

        $this->assertNotContains($bare->id, $results);
    }

    // ---- المدخلات العدائية: توسّع البحث ولا تُسقط الصفحة ----

    #[Test]
    public function an_unknown_attribute_key_is_ignored(): void
    {
        $results = $this->searchWith(['attr' => ['does_not_exist' => ['max' => 5]]]);

        $this->assertCount(2, $results);
    }

    /** نطاق على خاصية خيارات: الشكل لا يطابق النوع فيُهمَل */
    #[Test]
    public function a_range_on_an_option_attribute_is_ignored(): void
    {
        $results = $this->searchWith(['attr' => ['transmission' => ['max' => 5]]]);

        $this->assertCount(2, $results);
    }

    /** مطابقة تامة على خاصية رقمية تُهمَل كذلك */
    #[Test]
    public function an_equality_on_a_numeric_attribute_is_ignored(): void
    {
        $results = $this->searchWith(['attr' => ['mileage' => 40000]]);

        $this->assertCount(2, $results);
    }

    #[Test]
    public function a_non_array_attr_parameter_is_ignored(): void
    {
        $this->assertCount(2, $this->searchWith(['attr' => 'عبث']));
    }

    #[Test]
    public function nonsense_values_are_ignored(): void
    {
        $this->assertCount(2, $this->searchWith(['attr' => ['mileage' => ['max' => 'كثير']]]));
        $this->assertCount(2, $this->searchWith(['attr' => ['mileage' => ['max' => ['nested']]]]));
        $this->assertCount(2, $this->searchWith(['attr' => ['MILEAGE!' => ['max' => 5]]]));
    }

    #[Test]
    public function the_number_of_filters_is_capped(): void
    {
        $attr = [];

        for ($i = 0; $i < 40; $i++) {
            $attr["key_{$i}"] = ['max' => 1];
        }

        $criteria = SearchCriteria::fromRequest(Request::create('/', 'GET', ['attr' => $attr]));

        $this->assertCount(SearchCriteria::MAX_ATTRIBUTE_FILTERS, $criteria->attributes);
    }

    #[Test]
    public function a_hostile_url_does_not_break_the_page(): void
    {
        $this->get('/?attr[]=1&attr[mileage][]=x&attr[mileage][min][]=3')->assertOk();
    }

    // ---- الواجهة ----

    #[Test]
    public function the_filters_appear_only_after_a_category_is_chosen(): void
    {
        $this->get('/')->assertOk()->assertDontSee('مواصفات التصنيف');

        $this->get('/?category='.$this->category->id)
            ->assertOk()
            ->assertSee('مواصفات التصنيف')
            ->assertSee('name="attr[mileage][max]"', false)
            ->assertSee('name="attr[transmission]"', false);
    }
}
