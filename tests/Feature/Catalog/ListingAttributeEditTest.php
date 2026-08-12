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
 * تعديل المواصفات بعد النشر.
 *
 * كان النشر يكتب المواصفات والتعديل لا يمسّها إطلاقاً: من نشر سيارة
 * بممشى خاطئ لا يملك تصحيحه إلا بحذف الإعلان وإعادة نشره — وخسارة
 * مشاهداته ومحادثاته معه.
 *
 * وأثقل ما هنا هو الحالة الأخيرة: تغيير التصنيف. الكتابة كانت تحذف
 * صفات التصنيف المسموح وحدها، فتترك خلفها قيماً يتيمة يطابقها البحث
 * ولا تعرضها الصفحة.
 */
class ListingAttributeEditTest extends TestCase
{
    use RefreshDatabase;

    private Category $cars;

    private Category $flats;

    private Attribute $year;

    private Attribute $transmission;

    private Attribute $extras;

    private Attribute $rooms;

    private AttributeOption $automatic;

    private AttributeOption $manual;

    private AttributeOption $sunroof;

    private AttributeOption $leather;

    private Location $location;

    private User $user;

    private Listing $listing;

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

        $this->manual = AttributeOption::create([
            'attribute_id' => $this->transmission->id,
            'value_ar' => 'عادي', 'value_normalized' => 'عادي', 'sort_order' => 2,
        ]);

        $this->extras = Attribute::create([
            'attr_key' => 'extras', 'name_ar' => 'إضافات', 'data_type' => 'multi_option',
        ]);

        $this->sunroof = AttributeOption::create([
            'attribute_id' => $this->extras->id,
            'value_ar' => 'فتحة سقف', 'value_normalized' => 'فتحه سقف', 'sort_order' => 1,
        ]);

        $this->leather = AttributeOption::create([
            'attribute_id' => $this->extras->id,
            'value_ar' => 'مقاعد جلد', 'value_normalized' => 'مقاعد جلد', 'sort_order' => 2,
        ]);

        $this->rooms = Attribute::create([
            'attr_key' => 'rooms', 'name_ar' => 'عدد الغرف', 'data_type' => 'number', 'unit' => 'غرفة',
        ]);

        $this->cars->attributes()->sync([
            $this->year->id => ['is_filterable' => true, 'sort_order' => 1],
            $this->transmission->id => ['is_filterable' => true, 'sort_order' => 2],
            $this->extras->id => ['is_filterable' => true, 'sort_order' => 3],
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

        // الإعلان يُنشأ عبر المسار الحقيقي ليحمل مواصفات فعلية
        $this->post(route('listing.store'), $this->payload([
            'attributes' => [
                $this->year->id => 2018,
                $this->transmission->id => $this->automatic->id,
                $this->extras->id => [$this->sunroof->id],
            ],
        ]));

        $this->listing = Listing::firstOrFail();
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

    private function save(array $overrides = [])
    {
        return $this->put(route('account.listings.update', $this->listing), $this->payload($overrides));
    }

    private function valueOf(Attribute $attribute): ?object
    {
        return DB::table('listing_attribute_values')
            ->where('listing_id', $this->listing->id)
            ->where('attribute_id', $attribute->id)
            ->first();
    }

    // ═══ التحرير ═══

    #[Test]
    public function it_updates_a_number_value(): void
    {
        $this->save(['attributes' => [$this->year->id => 2020]])->assertRedirect();

        $this->assertSame(2020.0, (float) $this->valueOf($this->year)->value_number);
    }

    #[Test]
    public function it_updates_an_option_value(): void
    {
        $this->save([
            'attributes' => [$this->transmission->id => $this->manual->id],
        ])->assertRedirect();

        $this->assertSame($this->manual->id, (int) $this->valueOf($this->transmission)->option_id);
    }

    /**
     * الإفراغ يحذف الصفّ.
     *
     * لولا الحذف قبل الإدراج لبقيت القيمة القديمة إلى الأبد: المعلن
     * يمسح الحقل، ويحفظ، ويرى المواصفة كما كانت.
     */
    #[Test]
    public function clearing_a_field_removes_the_value(): void
    {
        $this->save(['attributes' => [$this->year->id => '']])->assertRedirect();

        $this->assertNull($this->valueOf($this->year));
    }

    #[Test]
    public function it_replaces_the_whole_multi_option_selection(): void
    {
        $this->save([
            'attributes' => [
                $this->extras->id => [$this->leather->id],
            ],
        ])->assertRedirect();

        $chosen = DB::table('listing_multi_options')
            ->where('listing_id', $this->listing->id)
            ->pluck('option_id')
            ->all();

        $this->assertSame([$this->leather->id], $chosen);
    }

    /** التحقق مشتقّ من تعريف الصفة — لا يُكتب في UpdateListingRequest */
    #[Test]
    public function a_value_outside_the_declared_range_is_refused(): void
    {
        $this->save(['attributes' => [$this->year->id => 1800]])
            ->assertSessionHasErrors('attributes.'.$this->year->id);

        $this->assertSame(2018.0, (float) $this->valueOf($this->year)->value_number);
    }

    /** خيار من صفة أخرى لا يمرّ ولو أُرسل بتعديل يدوي للنموذج */
    #[Test]
    public function an_option_from_another_attribute_is_refused(): void
    {
        $this->save(['attributes' => [$this->transmission->id => $this->sunroof->id]])
            ->assertSessionHasErrors('attributes.'.$this->transmission->id);
    }

    /**
     * صفة لا يملكها التصنيف تُقصى عند الكتابة.
     *
     * الحقول المخفية بـAlpine تُرسَل مع النموذج، فالحارس على الخادم
     * لا في العرض.
     */
    #[Test]
    public function an_attribute_outside_the_category_is_ignored(): void
    {
        $this->save([
            'attributes' => [
                $this->year->id => 2019,
                $this->rooms->id => 4,
            ],
        ])->assertRedirect();

        $this->assertNull($this->valueOf($this->rooms));
    }

    // ═══ تغيير التصنيف ═══

    /**
     * الحالة التي كانت تُنتج نتيجة بحث خاطئة بصمت.
     *
     * AttributeFilters يستعلم بـ(listing_id, attribute_id) بلا ربطٍ
     * بالتصنيف — فصفّ «سنة الصنع» الباقي على إعلانٍ صار شقةً يجعل
     * فلترة السيارات تُرجعها.
     */
    #[Test]
    public function moving_to_another_category_purges_the_old_values(): void
    {
        $this->save([
            'category_id' => $this->flats->id,
            'attributes' => [$this->rooms->id => 4],
        ])->assertRedirect();

        $this->assertNull($this->valueOf($this->year));
        $this->assertNull($this->valueOf($this->transmission));

        $this->assertSame(0, DB::table('listing_multi_options')
            ->where('listing_id', $this->listing->id)
            ->count());

        $this->assertSame(4.0, (float) $this->valueOf($this->rooms)->value_number);
    }

    /** القواعد تُشتقّ من التصنيف المرسَل لا من المحفوظ */
    #[Test]
    public function the_new_categorys_rules_apply_on_the_same_save(): void
    {
        $this->save([
            'category_id' => $this->flats->id,
            'attributes' => [$this->rooms->id => 'أربع غرف'],
        ])->assertSessionHasErrors('attributes.'.$this->rooms->id);
    }

    // ═══ النموذج ═══

    /**
     * القيم المحفوظة تصل إلى الحقول محدَّدةً سلفاً.
     *
     * الحالة السالبة هنا ليست زينة: نموذج يؤشّر **كل** الخيارات يمرّ
     * على توكيدٍ موجب وحده، والمعلن يحفظ فيكتسب إعلانه مواصفات لم
     * يخترها.
     *
     * والمطابقة بتعبير نمطي لا بنصّ: ‎@checked‎ تُخرج ‎checked="checked"‎
     * ويفصلها عن ‎value‎ سطرٌ جديد وإزاحة.
     */
    #[Test]
    public function the_edit_form_shows_the_saved_values(): void
    {
        $html = $this->get(route('account.listings.edit', $this->listing))
            ->assertOk()
            ->getContent();

        // الرقم في قيمة الحقل
        $this->assertStringContainsString('value="2018"', $html);

        // الخيار محدَّد سلفاً — لا مجرّد معروض في القائمة
        $this->assertMatchesRegularExpression(
            '/value="'.$this->automatic->id.'"\s+selected/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="'.$this->manual->id.'"\s+selected/',
            $html
        );

        // والخيار المتعدد مؤشَّر — وحده
        $this->assertMatchesRegularExpression(
            '/value="'.$this->sunroof->id.'"\s+checked/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="'.$this->leather->id.'"\s+checked/',
            $html
        );
    }

    /**
     * سنة 2018 لا 2018.0000.
     *
     * العمود decimal(18,4)، وتمريره خاماً إلى حقل number يعرض الكسور.
     */
    #[Test]
    public function a_decimal_column_does_not_leak_its_zeros_into_the_form(): void
    {
        $this->get(route('account.listings.edit', $this->listing))
            ->assertOk()
            ->assertDontSee('value="2018.0000"', false);
    }

    #[Test]
    public function another_user_cannot_open_the_edit_form(): void
    {
        $stranger = User::create([
            'name' => 'غريب',
            'phone' => '+967771111111',
            'email' => 'stranger@example.test',
            'password' => Hash::make('password1234'),
        ]);

        $this->actingAs($stranger)
            ->get(route('account.listings.edit', $this->listing))
            ->assertForbidden();
    }

    /** المواصفات لا تُحفظ في أعمدة الإعلان — safe() يستبعدها */
    #[Test]
    public function the_attributes_key_never_reaches_the_listings_table(): void
    {
        $this->save(['attributes' => [$this->year->id => 2020]])->assertRedirect();

        $this->assertArrayNotHasKey('attributes', $this->listing->fresh()->getAttributes());
    }
}
