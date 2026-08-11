<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Attribute;
use App\Modules\Shared\Models\AttributeOption;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الخصائص الخاصة بالتصنيف: من النموذج إلى الجدول إلى الصفحة.
 *
 * الجداول والموديلات والبذرة كانت موجودة منذ البداية، وكان
 * listing_attribute_values فارغاً تماماً — لا كاتب ولا قارئ. ما يلي
 * يغطّي المسار الذي كان مفقوداً، وأهمّه الحالة الثالثة: التصفية
 * بالتصنيف على الخادم، فالحقول المخفية في المتصفح تُرسَل كغيرها.
 */
class ListingAttributesTest extends TestCase
{
    use RefreshDatabase;

    private Category $cars;

    private Category $flats;

    private Attribute $year;

    private Attribute $transmission;

    private Attribute $rooms;

    private AttributeOption $automatic;

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
        $this->flats = Category::create(['name_ar' => 'شقق', 'slug' => 'flats', 'path' => '']);

        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);

        $this->year = Attribute::create([
            'attr_key' => 'year', 'name_ar' => 'سنة الصنع', 'data_type' => 'number',
            'unit' => 'سنة', 'min_value' => 1950, 'max_value' => 2030,
        ]);

        $this->transmission = Attribute::create([
            'attr_key' => 'transmission', 'name_ar' => 'ناقل الحركة', 'data_type' => 'option',
        ]);

        $this->automatic = AttributeOption::create([
            'attribute_id' => $this->transmission->id,
            'value_ar' => 'أوتوماتيك', 'value_normalized' => 'اوتوماتيك', 'sort_order' => 1,
        ]);

        $this->rooms = Attribute::create([
            'attr_key' => 'rooms', 'name_ar' => 'عدد الغرف', 'data_type' => 'number', 'unit' => 'غرفة',
        ]);

        $this->cars->attributes()->sync([
            $this->year->id => ['is_filterable' => true, 'sort_order' => 1],
            $this->transmission->id => ['is_filterable' => true, 'sort_order' => 2],
        ]);

        $this->flats->attributes()->sync([
            $this->rooms->id => ['is_filterable' => true, 'sort_order' => 1],
        ]);

        $this->user = User::create([
            'name' => 'معلن مختبَر',
            'phone' => '+967771234567',
            'email' => 'publisher@example.test',
            'password' => Hash::make('password1234'),
        ]);

        $this->user->markPhoneAsVerified();

        $this->actingAs($this->user);
    }

    private Location $location;

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

    #[Test]
    public function it_stores_the_values_of_the_chosen_category(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [
                $this->year->id => 2018,
                $this->transmission->id => $this->automatic->id,
            ],
        ]))->assertRedirect();

        $listing = Listing::firstOrFail();

        $this->assertDatabaseHas('listing_attribute_values', [
            'listing_id' => $listing->id,
            'attribute_id' => $this->year->id,
            'value_number' => 2018,
        ]);

        $this->assertDatabaseHas('listing_attribute_values', [
            'listing_id' => $listing->id,
            'attribute_id' => $this->transmission->id,
            'option_id' => $this->automatic->id,
        ]);
    }

    /**
     * الحالة التي تبرّر التصفية على الخادم.
     *
     * النموذج يعرض حقول كل التصنيفات ويُخفي غير المعني بـAlpine، والحقل
     * المخفي يُرسَل كغيره. فبلا تصفية يكتب المعلن «عدد الغرف» على سيارة
     * بتعديل بسيط في أدوات المتصفح.
     */
    #[Test]
    public function it_ignores_an_attribute_that_belongs_to_another_category(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [
                $this->year->id => 2018,
                $this->rooms->id => 4,
            ],
        ]))->assertRedirect();

        $listing = Listing::firstOrFail();

        $this->assertDatabaseMissing('listing_attribute_values', [
            'listing_id' => $listing->id,
            'attribute_id' => $this->rooms->id,
        ]);

        $this->assertSame(1, DB::table('listing_attribute_values')
            ->where('listing_id', $listing->id)->count());
    }

    /** الخيار يُتحقَّق مقابل خيارات صفته وحدها */
    #[Test]
    public function it_rejects_an_option_belonging_to_a_different_attribute(): void
    {
        $foreign = AttributeOption::create([
            'attribute_id' => $this->rooms->id,
            'value_ar' => 'خيار غريب', 'value_normalized' => 'خيار غريب', 'sort_order' => 1,
        ]);

        $this->post(route('listing.store'), $this->payload([
            'attributes' => [$this->transmission->id => $foreign->id],
        ]))->assertSessionHasErrors('attributes.'.$this->transmission->id);

        $this->assertDatabaseCount('listings', 0);
    }

    #[Test]
    public function it_rejects_a_number_outside_the_declared_range(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [$this->year->id => 1899],
        ]))->assertSessionHasErrors('attributes.'.$this->year->id);

        $this->assertDatabaseCount('listings', 0);
    }

    #[Test]
    public function it_rejects_text_in_a_numeric_attribute(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [$this->year->id => 'قديمة'],
        ]))->assertSessionHasErrors('attributes.'.$this->year->id);
    }

    /** حقل متروك فارغاً لا يُخزَّن صفاً فارغاً */
    #[Test]
    public function it_stores_nothing_for_a_blank_value(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [
                $this->year->id => 2018,
                $this->transmission->id => '',
            ],
        ]))->assertRedirect();

        $listing = Listing::firstOrFail();

        $this->assertDatabaseMissing('listing_attribute_values', [
            'listing_id' => $listing->id,
            'attribute_id' => $this->transmission->id,
        ]);
    }

    #[Test]
    public function a_listing_without_any_attribute_still_publishes(): void
    {
        $this->post(route('listing.store'), $this->payload())->assertRedirect();

        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseCount('listing_attribute_values', 0);
    }

    #[Test]
    public function the_stored_values_appear_on_the_listing_page(): void
    {
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [
                $this->year->id => 2018,
                $this->transmission->id => $this->automatic->id,
            ],
        ]));

        $listing = Listing::firstOrFail();

        $this->get(route('listing.show', $listing))
            ->assertOk()
            ->assertSee('المواصفات')
            ->assertSee('سنة الصنع')
            // بلا كسور: «2018» لا «2018.0000»
            ->assertSee('2018')
            ->assertSee('أوتوماتيك');
    }

    /** النموذج يحمل حقول التصنيفات التي لها مواصفات */
    #[Test]
    public function the_create_form_renders_the_attribute_fields(): void
    {
        $this->get(route('listing.create'))
            ->assertOk()
            ->assertSee('مواصفات التصنيف')
            ->assertSee('name="attributes['.$this->year->id.']"', false)
            ->assertSee('name="attributes['.$this->rooms->id.']"', false);
    }
}
