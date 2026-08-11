<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\TaxonomyAdministrator;
use App\Modules\Shared\Models\Attribute;
use App\Modules\Shared\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * إدارة الخصائص وربطها بالتصنيفات — للمدير وحده.
 *
 * الخاصية المُضافة هنا تظهر فوراً في نموذج النشر وفي فلاتر البحث بلا
 * نشر كود: كلاهما يقرأ من category_attributes وقت العرض. وهذا ما
 * يجعل الشاشة مجدية أصلاً — قبل توصيل نظام الخصائص كانت إضافة صفة
 * بلا أثر مرئي.
 */
class AttributeAdminController
{
    public function __construct(private readonly TaxonomyAdministrator $taxonomy) {}

    public function index(): View
    {
        return view('admin::taxonomy.attributes', [
            'attributes' => Attribute::query()
                ->with('options')
                ->withCount('categories')
                ->orderBy('attr_key')
                ->get(),

            // الفرعية وحدها تُربط: الخاصية تخصّ سلعة بعينها لا قسماً
            // كاملاً — «الممشى» تخصّ السيارات لا «سيارات ومركبات»
            'categories' => Category::whereNotNull('parent_id')
                ->with(['parent', 'attributes'])
                ->orderBy('path')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'attr_key' => [
                'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('attributes', 'attr_key'),
            ],
            'name_ar' => ['required', 'string', 'min:2', 'max:120'],
            'data_type' => ['required', Rule::in(['string', 'number', 'boolean', 'option', 'multi_option', 'date'])],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],
        ], [
            'attr_key.regex' => 'المفتاح يبدأ بحرف لاتيني صغير ويقبل الأرقام والشرطة السفلية.',
            'attr_key.unique' => 'هذا المفتاح مستخدم في خاصية أخرى.',
            'max_value.gte' => 'الحد الأعلى يجب ألا يقل عن الأدنى.',
        ]);

        $attribute = $this->taxonomy->createAttribute($request->user(), $data);

        return back()->with('status', "أُنشئت الخاصية «{$attribute->name_ar}».");
    }

    public function update(Request $request, Attribute $attribute): RedirectResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'min:2', 'max:120'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_value' => ['nullable', 'numeric'],
            'max_value' => ['nullable', 'numeric', 'gte:min_value'],
        ]);

        $this->taxonomy->updateAttribute($request->user(), $attribute, $data);

        return back()->with('status', "حُدِّثت الخاصية «{$attribute->name_ar}».");
    }

    public function storeOption(Request $request, Attribute $attribute): RedirectResponse
    {
        if (! in_array($attribute->data_type, ['option', 'multi_option'], true)) {
            return back()->with('error', 'الخيارات تخصّ الخصائص من نوع «قائمة» فقط.');
        }

        $data = $request->validate([
            'value_ar' => [
                'required', 'string', 'min:1', 'max:120',
                Rule::unique('attribute_options', 'value_ar')
                    ->where('attribute_id', $attribute->id),
            ],
        ], [
            'value_ar.unique' => 'هذا الخيار موجود في الخاصية نفسها.',
        ]);

        $this->taxonomy->addOption($request->user(), $attribute, $data['value_ar']);

        return back()->with('status', 'أُضيف الخيار.');
    }

    /** ربط خصائص تصنيف — استبدال كامل لما هو مؤشَّر في الشاشة */
    public function syncCategory(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'attributes' => ['nullable', 'array'],
            'attributes.*' => ['integer', 'exists:attributes,id'],
            'required' => ['nullable', 'array'],
            'filterable' => ['nullable', 'array'],
        ]);

        $required = array_map('intval', $data['required'] ?? []);
        $filterable = array_map('intval', $data['filterable'] ?? []);

        $pivot = [];
        $order = 1;

        foreach ($data['attributes'] ?? [] as $id) {
            $pivot[(int) $id] = [
                'is_required' => in_array((int) $id, $required, true),
                // الافتراض هو القابلية للفلترة: الخاصية التي لا تُفلتر
                // تُعرض ولا تُبحث، وهو استثناء لا قاعدة
                'is_filterable' => $filterable === [] || in_array((int) $id, $filterable, true),
                'sort_order' => $order++,
            ];
        }

        $this->taxonomy->syncCategoryAttributes($request->user(), $category, $pivot);

        return back()->with('status', "حُدِّثت خصائص «{$category->name_ar}».");
    }
}
