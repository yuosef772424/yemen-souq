<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Shared\Models\Attribute;
use App\Modules\Shared\Models\AttributeOption;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الخيارات المتعددة: من النموذج إلى جدولها إلى الفلترة.
 *
 * النوع كان معلَناً في attributes.data_type منذ البداية، وجدول تخزينه
 * قائماً وفارغاً — آخر بند مؤجَّل من نظام الخصائص.
 *
 * وهو أول ما يشطب سطراً من سجلّ السكيمة المؤجَّلة، فيفرض DeferredSchemaTest
 * تحديث السجلّ في نفس الفرع.
 */
class MultiOptionAttributeTest extends TestCase
{
    use RefreshDatabase;

    private Category $cars;

    private Attribute $features;

    private AttributeOption $ac;

    private AttributeOption $sunroof;

    private AttributeOption $camera;

    private Location $location;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'YER',
            'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true,
        ]);

        ExchangeRate::create([
            'currency_code' => 'YER', 'rate_to_base' => 1,
            'effective_date' => now()->toDateString(), 'source' => 'test', 'created_at' => now(),
        ]);

        $this->cars = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);

        $this->features = Attribute::create([
            'attr_key' => 'features', 'name_ar' => 'الكماليات', 'data_type' => 'multi_option',
        ]);

        foreach (['مكيّف' => 'ac', 'فتحة سقف' => 'sunroof', 'كاميرا خلفية' => 'camera'] as $label => $prop) {
            $this->{$prop} = AttributeOption::create([
                'attribute_id' => $this->features->id,
                'value_ar' => $label,
                'sort_order' => count((array) $this->features->options) + 1,
            ]);
        }

        $this->cars->attributes()->sync([
            $this->features->id => ['is_filterable' => true, 'sort_order' => 1],
        ]);

        $this->user = User::create([
            'name' => 'معلن مختبَر', 'phone' => '+967771234567',
            'email' => 'publisher@example.test', 'password' => Hash::make('password1234'),
        ]);

        $this->user->markPhoneAsVerified();
        $this->actingAs($this->user);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'تويوتا كورولا وكالة بحالة ممتازة',
            'category_id' => $this->cars->id,
            'location_id' => $this->location->id,
            'price_type' => 'fixed',
            'price_amount' => 45000,
            'currency_code' => 'YER',
            'contact_phone' => '+967771234567',
        ], $overrides);
    }

    /** @return list<int> */
    private function searchWith(array $query): array
    {
        $criteria = SearchCriteria::fromRequest(Request::create('/', 'GET', $query));

        return app(SearchQueryBuilder::class)->build($criteria)->pluck('listings.id')->all();
    }

    // ---- الكتابة ----

    #[Test]
    public function several_options_are_stored_for_one_attribute(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [$this->features->id => [$this->ac->id, $this->sunroof->id]],
        ]))->assertRedirect();

        $listing = Listing::firstOrFail();

        $this->assertSame(2, DB::table('listing_multi_options')
            ->where('listing_id', $listing->id)->count());

        foreach ([$this->ac, $this->sunroof] as $option) {
            $this->assertDatabaseHas('listing_multi_options', [
                'listing_id' => $listing->id,
                'attribute_id' => $this->features->id,
                'option_id' => $option->id,
            ]);
        }
    }

    /** لا صفّ في جدول القيم المفردة — الخيارات المتعددة لها جدولها */
    #[Test]
    public function multi_values_do_not_touch_the_single_value_table(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [$this->features->id => [$this->ac->id]],
        ]));

        $this->assertDatabaseCount('listing_attribute_values', 0);
    }

    #[Test]
    public function an_option_from_another_attribute_is_refused(): void
    {
        $other = Attribute::create(['attr_key' => 'color', 'name_ar' => 'اللون', 'data_type' => 'option']);
        $foreign = AttributeOption::create([
            'attribute_id' => $other->id, 'value_ar' => 'أحمر', 'sort_order' => 1,
        ]);

        $this->post(route('listing.store'), $this->payload([
            'attributes' => [$this->features->id => [$foreign->id]],
        ]))->assertSessionHasErrors('attributes.'.$this->features->id.'.0');

        $this->assertDatabaseCount('listings', 0);
    }

    #[Test]
    public function choosing_nothing_stores_nothing(): void
    {
        $this->post(route('listing.store'), $this->payload())->assertRedirect();

        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseCount('listing_multi_options', 0);
    }

    // ---- العرض ----

    #[Test]
    public function the_chosen_options_appear_on_the_listing_page(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [$this->features->id => [$this->camera->id, $this->ac->id]],
        ]));

        $listing = Listing::firstOrFail();

        $this->get(route('listing.show', $listing))
            ->assertOk()
            ->assertSee('الكماليات')
            ->assertSee('مكيّف')
            ->assertSee('كاميرا خلفية');
    }

    /** الترتيب بتعريف الخيارات لا بترتيب اختيار المعلن */
    #[Test]
    public function options_display_in_their_defined_order(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [$this->features->id => [$this->camera->id, $this->ac->id]],
        ]));

        $listing = Listing::firstOrFail();

        $this->get(route('listing.show', $listing))
            ->assertSeeInOrder(['مكيّف', 'كاميرا خلفية']);
    }

    // ---- الفلترة ----

    #[Test]
    public function filtering_by_one_option_matches_listings_that_have_it(): void
    {
        [$withAc, $withSunroof] = $this->twoListings();

        $results = $this->searchWith(['attr' => ['features' => [$this->ac->id]]]);

        $this->assertSame([$withAc->id], $results);
        $this->assertNotContains($withSunroof->id, $results);
    }

    /**
     * الدلالة «أيّ منها» لا «كلها».
     *
     * الباحث الذي يؤشّر ثلاث كماليات يقصد «يهمّني أيٌّ منها» غالباً،
     * واشتراط الكل يُرجع صفراً في أغلب الحالات — وفلتر يُفرغ النتائج
     * يُهجَر.
     */
    #[Test]
    public function filtering_by_several_options_means_any_of_them(): void
    {
        [$withAc, $withSunroof] = $this->twoListings();

        $results = $this->searchWith([
            'attr' => ['features' => [$this->ac->id, $this->sunroof->id]],
        ]);

        $this->assertEqualsCanonicalizing([$withAc->id, $withSunroof->id], $results);
    }

    #[Test]
    public function a_listing_without_the_attribute_is_excluded(): void
    {
        $this->twoListings();

        $bare = $this->listingRow('إعلان بلا كماليات');

        $results = $this->searchWith(['attr' => ['features' => [$this->ac->id]]]);

        $this->assertNotContains($bare->id, $results);
    }

    // ---- مدخلات عدائية ----

    #[Test]
    public function nonsense_option_ids_are_ignored(): void
    {
        $this->twoListings();

        $this->assertCount(2, $this->searchWith(['attr' => ['features' => ['abc', -1, 0]]]));
        $this->assertCount(2, $this->searchWith(['attr' => ['features' => [['nested']]]]));
    }

    #[Test]
    public function the_number_of_options_in_one_filter_is_capped(): void
    {
        $criteria = SearchCriteria::fromRequest(Request::create('/', 'GET', [
            'attr' => ['features' => range(1, 80)],
        ]));

        $this->assertCount(
            SearchCriteria::MAX_OPTIONS_PER_FILTER,
            $criteria->attributes[0]->anyOf
        );
    }

    /** مصفوفة فيها min تبقى نطاقاً ولو حملت مفاتيح أخرى */
    #[Test]
    public function a_range_is_not_mistaken_for_an_option_list(): void
    {
        $criteria = SearchCriteria::fromRequest(Request::create('/', 'GET', [
            'attr' => ['features' => ['min' => 5, 0 => 3]],
        ]));

        $this->assertTrue($criteria->attributes[0]->isRange());
        $this->assertFalse($criteria->attributes[0]->isAnyOf());
    }

    #[Test]
    public function a_hostile_url_does_not_break_the_page(): void
    {
        $this->get('/?attr[features][]=x&attr[features][][]=1&attr[features][min][]=2')->assertOk();
    }

    // ---- مساعدات ----

    /** @return array{0: Listing, 1: Listing} */
    private function twoListings(): array
    {
        $withAc = $this->listingRow('سيارة بمكيّف');
        $withSunroof = $this->listingRow('سيارة بفتحة سقف');

        DB::table('listing_multi_options')->insert([
            ['listing_id' => $withAc->id, 'attribute_id' => $this->features->id, 'option_id' => $this->ac->id],
            ['listing_id' => $withSunroof->id, 'attribute_id' => $this->features->id, 'option_id' => $this->sunroof->id],
        ]);

        return [$withAc, $withSunroof];
    }

    private function listingRow(string $title): Listing
    {
        return Listing::create([
            'user_id' => $this->user->id,
            'category_id' => $this->cars->id,
            'location_id' => $this->location->id,
            'title' => $title,
            'price_amount' => 45000,
            'currency_code' => 'YER',
            'price_type' => 'fixed',
            'sale_type' => 'direct',
            'status' => 'active',
            'contact_phone' => '+967771234567',
            'published_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }
}
