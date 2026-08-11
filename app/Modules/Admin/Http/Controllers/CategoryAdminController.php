<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\TaxonomyAdministrator;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * إدارة شجرة التصنيفات — للمدير وحده.
 *
 * لماذا للمدير لا للمشرف: المشرف يراجع محتوى قائماً، والتصنيف بنية
 * يُبنى عليها كل شيء آخر — نموذج النشر وفلاتر البحث وتوزيع الإعلانات.
 * وتعطيل تصنيف يُخفي كل إعلاناته دفعةً واحدة، وهو أثر أوسع من أي
 * إجراء إشرافي على إعلان.
 */
class CategoryAdminController
{
    public function __construct(private readonly TaxonomyAdministrator $taxonomy) {}

    public function index(): View
    {
        return view('admin::taxonomy.categories', [
            // عدّ الإعلانات باستعلام فرعي لا withCount: نفس حجة
            // UserController — لا نضيف علاقة listings() إلى موديل
            // مشترك فنربطه بوحدة الكتالوج
            'categories' => Category::query()
                ->withCount('attributes')
                ->addSelect(['listings_count' => Listing::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('listings.category_id', 'categories.id')
                    ->whereNull('listings.deleted_at'),
                ])
                ->orderBy('path')
                ->get(),

            'roots' => Category::whereNull('parent_id')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $category = $this->taxonomy->createCategory($request->user(), $data);

        return back()->with('status', "أُنشئ التصنيف «{$category->name_ar}».");
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);

        $this->taxonomy->updateCategory($request->user(), $category, $data);

        return back()->with('status', "حُدِّث التصنيف «{$category->name_ar}».");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => [
                'required', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('categories', 'slug')->ignore($category?->id),
            ],
            // الأب يُثبَّت عند الإنشاء ولا يُنقل بعده: نقل تصنيف يعيد
            // حساب path لكل فروعه، وpath هو ما تعتمد عليه فلترة الشجرة
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->whereNull('parent_id'),
            ],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
            'allows_auction' => ['nullable', 'boolean'],
        ], [
            'slug.regex' => 'المُعرِّف يقبل الحروف اللاتينية الصغيرة والأرقام والشرطة فقط.',
            'slug.unique' => 'هذا المُعرِّف مستخدم في تصنيف آخر.',
            'parent_id.exists' => 'التصنيف الأب يجب أن يكون تصنيفاً رئيسياً.',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['allows_auction'] = $request->boolean('allows_auction');
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['path'] = $category?->path ?? '';

        if ($category !== null) {
            unset($data['parent_id']);
        }

        return $data;
    }
}
