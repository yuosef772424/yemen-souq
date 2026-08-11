<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Shared\Models\Attribute;
use App\Modules\Shared\Models\AttributeOption;
use App\Modules\Shared\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * تحرير بنية السوق: التصنيفات والخصائص وربطهما.
 *
 * ═══ لماذا لم تكن هذه الشاشة موجودة ═══
 *
 * التصنيفات والخصائص كانت تُزرع بـCategorySeeder وحده، أي أن إضافة
 * تصنيف واحد تتطلب تعديل ثابت في الكود ثم تشغيل البذرة على الخادم.
 * وهذا مقبول ما دامت الشجرة ثابتة، ويصير عائقاً حقيقياً بعد توصيل
 * نظام الخصائص: الخاصية الجديدة تظهر فوراً في نموذج النشر وفي فلاتر
 * البحث، فقيمة إضافتها آنية — والطريق إليها كان يمرّ بنشر كود.
 *
 * ═══ لماذا لا حذف ═══
 *
 * listings.category_id مقيَّد بـrestrictOnDelete، فحذف تصنيف عليه
 * إعلانات يفشل على مستوى قاعدة البيانات. والأسوأ ما لا يفشل: حذف
 * تصنيف فارغ له أبناء يمسحهم بـcascadeOnDelete، وحذف خاصية يمسح كل
 * قيمها في listing_attribute_values — أي بيانات معلنين لا بنية.
 *
 * فالتعطيل هو الحذف هنا: is_active يُخفي التصنيف من التصفّح والنشر
 * ويُبقي إعلاناته وسجلّها. وما يُخفى يمكن إرجاعه، وما يُمحى لا.
 *
 * ═══ لماذا كل تغيير يُسجَّل ═══
 *
 * تعطيل تصنيف يُخفي كل إعلاناته دفعةً واحدة. وبلا سجل يصير السؤال
 * «لماذا اختفى قسم السيارات؟» بلا جواب — وهو أثر أوسع من أي إجراء
 * على إعلان واحد.
 */
final class TaxonomyAdministrator
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(?User $admin, array $data): Category
    {
        $category = Category::create($data);

        $this->audit->log($admin, AdminAction::CategoryCreated, $category, meta: [
            'name' => $category->name_ar,
            'parent_id' => $category->parent_id,
        ]);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(?User $admin, Category $category, array $data): Category
    {
        $before = $category->only(['name_ar', 'slug', 'is_active', 'allows_auction', 'sort_order']);

        $category->update($data);

        $this->audit->log($admin, AdminAction::CategoryUpdated, $category, meta: [
            'before' => $before,
            'after' => $category->only(['name_ar', 'slug', 'is_active', 'allows_auction', 'sort_order']),
        ]);

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAttribute(?User $admin, array $data): Attribute
    {
        $attribute = Attribute::create($data);

        $this->audit->log($admin, AdminAction::AttributeCreated, $attribute, meta: [
            'attr_key' => $attribute->attr_key,
            'data_type' => $attribute->data_type,
        ]);

        return $attribute;
    }

    /**
     * تعديل خاصية بلا مسّ attr_key ولا data_type.
     *
     * المفتاح يظهر في روابط البحث المحفوظة والمُشارَكة
     * (attr[mileage][max]=100000)، وتغييره يكسرها بصمت.
     *
     * والنوع يحدّد أي عمود يحمل القيمة في listing_attribute_values.
     * تغييره من رقم إلى خيار يترك آلاف القيم في value_number لا يقرأها
     * أحد، ويجعل الخاصية تبدو فارغة على كل إعلان يملكها.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAttribute(?User $admin, Attribute $attribute, array $data): Attribute
    {
        unset($data['attr_key'], $data['data_type']);

        $before = $attribute->only(['name_ar', 'unit', 'min_value', 'max_value']);

        $attribute->update($data);

        $this->audit->log($admin, AdminAction::AttributeUpdated, $attribute, meta: [
            'before' => $before,
            'after' => $attribute->only(['name_ar', 'unit', 'min_value', 'max_value']),
        ]);

        return $attribute;
    }

    public function addOption(?User $admin, Attribute $attribute, string $value): AttributeOption
    {
        $option = AttributeOption::create([
            'attribute_id' => $attribute->id,
            'value_ar' => $value,
            'sort_order' => (int) $attribute->options()->max('sort_order') + 1,
        ]);

        $this->audit->log($admin, AdminAction::AttributeOptionAdded, $attribute, meta: [
            'option' => $value,
        ]);

        return $option;
    }

    /**
     * ربط الخصائص بتصنيف — استبدال كامل لا إضافة.
     *
     * sync لا syncWithoutDetaching: الشاشة تعرض كل الخصائص بمربّعات
     * اختيار، فإزالة العلامة يجب أن تفكّ الربط. والإضافة وحدها تجعل
     * الشاشة تَعِد بما لا تفعل.
     *
     * وفكّ الربط لا يمسّ listing_attribute_values: القيم تبقى، وتعود
     * للظهور إن أُعيد الربط. حذفها يعني أن خطأ نقرة واحدة يمحو مواصفات
     * كل إعلان في التصنيف.
     *
     * @param  array<int, array{is_required: bool, is_filterable: bool, sort_order: int}>  $attributes
     */
    public function syncCategoryAttributes(?User $admin, Category $category, array $attributes): void
    {
        $before = $category->attributes()->pluck('attr_key')->sort()->values()->all();

        DB::transaction(fn () => $category->attributes()->sync($attributes));

        $after = $category->attributes()->pluck('attr_key')->sort()->values()->all();

        $this->audit->log($admin, AdminAction::CategoryAttributesChanged, $category, meta: [
            'before' => $before,
            'after' => $after,
        ]);
    }
}
