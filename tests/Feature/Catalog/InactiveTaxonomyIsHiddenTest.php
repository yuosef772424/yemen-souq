<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * تعطيل تصنيف أو منطقة من لوحة الإدارة يَعِد بإخفائه من التصفّح والنشر —
 * نصّ الشاشة نفسه يقوله. وكان الوعد يفي بنصفه فقط: `Category::active()`
 * تُقصي الجذور المعطَّلة، لكن `with('children')` كانت تجلب كل الأبناء
 * بصرف النظر عن حالتهم — فيبقى الفرع المعطَّل في نموذج النشر تماماً كما
 * كان قبل تعطيله.
 *
 * والعكس مطلوب في التعديل: إعلانٌ قائم على فرعٍ عُطِّل بعد نشره يجب أن
 * يستمر يعرض ذلك الفرع في نموذج تعديله — وإلا فتح صاحبه الصفحة ووجد
 * حقل التصنيف فارغاً، وأي حفظ يجبره على اختيار غير ما نشر أصلاً.
 */
class InactiveTaxonomyIsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $root;

    private Category $activeChild;

    private Category $inactiveChild;

    private Location $governorate;

    private Location $activeDistrict;

    private Location $inactiveDistrict;

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

        $this->root = Category::create(['name_ar' => 'سيارات ومركبات', 'slug' => 'vehicles', 'path' => '']);
        $this->activeChild = Category::create([
            'parent_id' => $this->root->id, 'name_ar' => 'سيارات للبيع', 'slug' => 'vehicles-1', 'path' => '',
        ]);
        $this->inactiveChild = Category::create([
            'parent_id' => $this->root->id, 'name_ar' => 'فرع معطَّل', 'slug' => 'vehicles-x', 'path' => '',
            'is_active' => false,
        ]);

        $this->governorate = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
        $this->activeDistrict = Location::create([
            'parent_id' => $this->governorate->id, 'name_ar' => 'التحرير', 'slug' => 'tahrir',
            'level' => 'district', 'path' => '',
        ]);
        $this->inactiveDistrict = Location::create([
            'parent_id' => $this->governorate->id, 'name_ar' => 'منطقة معطَّلة', 'slug' => 'disabled-district',
            'level' => 'district', 'path' => '', 'is_active' => false,
        ]);

        $this->user = User::create([
            'name' => 'معلن مختبَر', 'phone' => '+967771234567',
            'email' => 'publisher@example.test', 'password' => Hash::make('password1234'),
        ]);
        $this->user->markPhoneAsVerified();
    }

    #[Test]
    public function the_publish_form_hides_a_disabled_category_and_district(): void
    {
        $this->actingAs($this->user)->get(route('listing.create'))
            ->assertOk()
            ->assertSee($this->activeChild->name_ar)
            ->assertSee($this->activeDistrict->name_ar)
            ->assertDontSee($this->inactiveChild->name_ar)
            ->assertDontSee($this->inactiveDistrict->name_ar);
    }

    #[Test]
    public function editing_a_listing_still_shows_its_own_disabled_category_and_district(): void
    {
        $listing = Listing::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->inactiveChild->id,
            'location_id' => $this->inactiveDistrict->id,
            'currency_code' => 'YER',
            'status' => 'active',
        ]);

        $this->actingAs($this->user)->get(route('account.listings.edit', $listing))
            ->assertOk()
            // الفرعان المعطَّلان يظهران لأنهما فرعا هذا الإعلان بالذات
            ->assertSee($this->inactiveChild->name_ar)
            ->assertSee($this->inactiveDistrict->name_ar)
            // بلا أن يتسرّب فرعٌ معطَّل من محافظة أو تصنيف آخر بسبب
            // orWhere غير مُجمَّع في القيد
            ->assertSee($this->activeChild->name_ar)
            ->assertSee($this->activeDistrict->name_ar);
    }

    /**
     * الحارس الحقيقي: بلا تجميع الشرط بقوس، orWhere('id', ...) يهرب من
     * قيد parent_id الذي يضيفه التحميل الشغوف تلقائياً — فتظهر فروعٌ
     * معطَّلة من تصنيفات أخرى لمجرّد أن أحد المعرّفات تطابق بالمصادفة.
     */
    #[Test]
    public function a_disabled_child_of_a_different_root_never_leaks_in(): void
    {
        $otherRoot = Category::create(['name_ar' => 'أخرى', 'slug' => 'other', 'path' => '']);
        $unrelatedInactive = Category::create([
            'parent_id' => $otherRoot->id, 'name_ar' => 'فرع آخر معطَّل', 'slug' => 'other-x', 'path' => '',
            'is_active' => false,
        ]);

        $listing = Listing::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->inactiveChild->id,
            'location_id' => $this->activeDistrict->id,
            'currency_code' => 'YER',
            'status' => 'active',
        ]);

        $this->actingAs($this->user)->get(route('account.listings.edit', $listing))
            ->assertOk()
            ->assertDontSee($unrelatedInactive->name_ar);
    }
}
