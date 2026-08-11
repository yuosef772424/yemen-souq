<?php

namespace Tests\Feature\Admin;

use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Admin\Enums\UserRole;
use App\Modules\Shared\Models\Attribute;
use App\Modules\Shared\Models\AttributeOption;
use App\Modules\Shared\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Admin\Concerns\MakesAdminUsers;
use Tests\TestCase;

/**
 * إدارة بنية السوق.
 *
 * كانت التصنيفات والخصائص تُحرَّر بتعديل ثابت في CategorySeeder ثم
 * تشغيل البذرة. وبعد توصيل نظام الخصائص صار للخاصية أثر آنيّ — تظهر
 * في نموذج النشر وفي فلاتر البحث — فصار الطريق إليها عبر نشر كود
 * عائقاً حقيقياً.
 *
 * وأهم ما يُختبر هنا ليس الإنشاء بل ما يُمنع: المفتاح والنوع بعد
 * الإنشاء، ووصول غير المدير.
 */
class TaxonomyManagementTest extends TestCase
{
    use MakesAdminUsers, RefreshDatabase;

    /**
     * عرض الشاشتين على بيانات حقيقية.
     *
     * بلا هذا يمرّ خطأ صياغة في Blade إلى الإنتاج: بقية الاختبارات
     * تُرسل POST وتقرأ قاعدة البيانات، ولا يفتح أيٌّ منها الصفحة.
     */
    #[Test]
    public function both_screens_render_for_an_admin(): void
    {
        $this->seedMarketplace();

        $admin = $this->userWithRole(UserRole::Admin);

        $this->actingAs($admin)->get(route('admin.categories'))
            ->assertOk()
            ->assertSee('تصنيف جديد')
            ->assertSee('سيارات للبيع');

        $this->actingAs($admin)->get(route('admin.attributes'))
            ->assertOk()
            ->assertSee('خاصية جديدة')
            ->assertSee('الممشى')
            ->assertSee('ربط الخصائص بالتصنيفات');
    }

    #[Test]
    public function an_admin_creates_a_category(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.categories.store'), [
                'name_ar' => 'قوارب',
                'slug' => 'boats',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['slug' => 'boats', 'name_ar' => 'قوارب']);

        $this->assertDatabaseHas('admin_actions', [
            'action' => AdminAction::CategoryCreated->value,
        ]);
    }

    /** التطبيع والمسار يحسبهما الموديل — لا يُمرَّران من النموذج */
    #[Test]
    public function a_created_category_gets_its_derived_columns(): void
    {
        $parent = Category::create(['name_ar' => 'مركبات', 'slug' => 'vehicles', 'path' => '']);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.categories.store'), [
                'name_ar' => 'قوارب صيد',
                'slug' => 'fishing-boats',
                'parent_id' => $parent->id,
            ]);

        $child = Category::where('slug', 'fishing-boats')->firstOrFail();

        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame(1, $child->depth);
        $this->assertSame($parent->id.'/'.$child->id, $child->path);
    }

    #[Test]
    public function deactivating_a_category_keeps_it_and_its_listings(): void
    {
        $category = Category::create(['name_ar' => 'قوارب', 'slug' => 'boats', 'path' => '']);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.categories.update', $category), [
                'name_ar' => 'قوارب',
                'slug' => 'boats',
            ])
            ->assertRedirect();

        $this->assertFalse($category->fresh()->is_active);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    #[Test]
    public function an_admin_creates_an_attribute_and_adds_options(): void
    {
        $admin = $this->userWithRole(UserRole::Admin);

        $this->actingAs($admin)
            ->post(route('admin.attributes.store'), [
                'attr_key' => 'color',
                'name_ar' => 'اللون',
                'data_type' => 'option',
            ])
            ->assertRedirect();

        $attribute = Attribute::where('attr_key', 'color')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.attributes.options', $attribute), ['value_ar' => 'أبيض'])
            ->assertRedirect();

        $this->assertDatabaseHas('attribute_options', [
            'attribute_id' => $attribute->id,
            'value_ar' => 'أبيض',
        ]);
    }

    /**
     * المفتاح يظهر في روابط بحث محفوظة ومُشارَكة، والنوع يحدّد أي عمود
     * يحمل القيمة. تغيير أيّهما يكسر بيانات قائمة بصمت.
     */
    #[Test]
    public function the_key_and_type_are_immutable(): void
    {
        $attribute = Attribute::create([
            'attr_key' => 'mileage', 'name_ar' => 'الممشى', 'data_type' => 'number',
        ]);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.attributes.update', $attribute), [
                'name_ar' => 'المسافة المقطوعة',
                'attr_key' => 'distance',
                'data_type' => 'option',
            ])
            ->assertRedirect();

        $attribute->refresh();

        $this->assertSame('mileage', $attribute->attr_key);
        $this->assertSame('number', $attribute->data_type);
        $this->assertSame('المسافة المقطوعة', $attribute->name_ar);
    }

    #[Test]
    public function options_are_refused_on_a_non_option_attribute(): void
    {
        $attribute = Attribute::create([
            'attr_key' => 'mileage', 'name_ar' => 'الممشى', 'data_type' => 'number',
        ]);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.attributes.options', $attribute), ['value_ar' => 'أبيض']);

        $this->assertDatabaseCount('attribute_options', 0);
    }

    #[Test]
    public function linking_an_attribute_makes_it_appear_in_search_filters(): void
    {
        // العملات ضرورية لصفحة البحث: Currency::base() تستعمل
        // firstOrFail، فبلا عملة أساس تُرجع الصفحة 404 لا 500
        $this->seedMarketplace();

        $category = Category::create(['name_ar' => 'قوارب', 'slug' => 'boats', 'path' => '', 'is_active' => true]);

        $attribute = Attribute::create([
            'attr_key' => 'length', 'name_ar' => 'الطول', 'data_type' => 'number', 'unit' => 'م',
        ]);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.categories.attributes', $category), [
                'attributes' => [$attribute->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('category_attributes', [
            'category_id' => $category->id,
            'attribute_id' => $attribute->id,
            'is_filterable' => true,
        ]);

        // الأثر الفعلي: الفلتر يظهر في صفحة البحث بلا نشر كود
        $this->get('/?category='.$category->id)
            ->assertOk()
            ->assertSee('name="attr[length][max]"', false);
    }

    /** إزالة العلامة تفكّ الربط — الشاشة تعرض الكل بمربّعات اختيار */
    #[Test]
    public function unchecking_an_attribute_detaches_it(): void
    {
        $category = Category::create(['name_ar' => 'قوارب', 'slug' => 'boats', 'path' => '']);
        $attribute = Attribute::create(['attr_key' => 'length', 'name_ar' => 'الطول', 'data_type' => 'number']);

        $category->attributes()->sync([$attribute->id => ['is_filterable' => true, 'sort_order' => 1]]);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.categories.attributes', $category), [])
            ->assertRedirect();

        $this->assertDatabaseCount('category_attributes', 0);
    }

    /** فكّ الربط لا يمسّ قيم المعلنين المحفوظة */
    #[Test]
    public function detaching_keeps_the_stored_values(): void
    {
        $this->seedMarketplace();

        $category = Category::whereNotNull('parent_id')->has('attributes')->firstOrFail();
        $attribute = $category->attributes->first();
        $listing = $this->listing(['category_id' => $category->id]);

        DB::table('listing_attribute_values')->insert([
            'listing_id' => $listing->id,
            'attribute_id' => $attribute->id,
            'value_string' => null, 'value_number' => 5, 'value_boolean' => null,
            'value_date' => null, 'option_id' => null,
        ]);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.categories.attributes', $category), []);

        $this->assertDatabaseHas('listing_attribute_values', [
            'listing_id' => $listing->id,
            'attribute_id' => $attribute->id,
        ]);
    }

    // ---- الوصول ----

    #[Test]
    public function a_moderator_cannot_reach_the_taxonomy_screens(): void
    {
        $moderator = $this->userWithRole(UserRole::Moderator);

        $this->actingAs($moderator)->get(route('admin.categories'))->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.attributes'))->assertForbidden();
    }

    /**
     * العضو يرى 404 لا 403.
     *
     * وهو سلوك EnsureIsStaff المقصود: 403 يؤكد للمتطفّل أن المسار قائم.
     * والمشرف يرى 403 لأنه موظف بالفعل — يعرف أن اللوحة موجودة، ويُمنع
     * من قسم فيها.
     */
    #[Test]
    public function a_member_does_not_learn_that_the_screens_exist(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Member))
            ->post(route('admin.categories.store'), ['name_ar' => 'قوارب', 'slug' => 'boats'])
            ->assertNotFound();

        $this->assertDatabaseMissing('categories', ['slug' => 'boats']);
    }

    #[Test]
    public function a_duplicate_slug_is_refused(): void
    {
        Category::create(['name_ar' => 'قوارب', 'slug' => 'boats', 'path' => '']);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.categories.store'), ['name_ar' => 'قوارب أخرى', 'slug' => 'boats'])
            ->assertSessionHasErrors('slug');
    }

    #[Test]
    public function a_duplicate_attribute_key_is_refused(): void
    {
        Attribute::create(['attr_key' => 'mileage', 'name_ar' => 'الممشى', 'data_type' => 'number']);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.attributes.store'), [
                'attr_key' => 'mileage', 'name_ar' => 'ممشى آخر', 'data_type' => 'number',
            ])
            ->assertSessionHasErrors('attr_key');
    }

    #[Test]
    public function a_duplicate_option_within_the_same_attribute_is_refused(): void
    {
        $attribute = Attribute::create(['attr_key' => 'color', 'name_ar' => 'اللون', 'data_type' => 'option']);

        AttributeOption::create([
            'attribute_id' => $attribute->id, 'value_ar' => 'أبيض', 'sort_order' => 1,
        ]);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->post(route('admin.attributes.options', $attribute), ['value_ar' => 'أبيض'])
            ->assertSessionHasErrors('value_ar');

        $this->assertDatabaseCount('attribute_options', 1);
    }
}
