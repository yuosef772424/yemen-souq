@extends('admin::layout')

@section('title', 'التصنيفات')

@section('content')

{{--
    الترتيب بـpath لا بالاسم: path يحمل شجرة الأنساب («1/7»)، فالفرز
    عليه يضع كل ابن تحت أبيه مباشرة بلا استعلام متكرر ولا ترتيب في PHP.
--}}

<div class="flex flex-wrap items-baseline justify-between gap-3">
    <h1 class="text-lg font-semibold">التصنيفات</h1>
    <p class="text-sm text-muted">{{ $categories->count() }} تصنيفاً</p>
</div>

<div class="mt-5 grid gap-5 lg:grid-cols-[1fr_22rem]">

    {{-- ═══ الشجرة ═══ --}}
    <div class="overflow-hidden rounded-2xl border border-line bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-line bg-tint/50 text-right text-xs text-muted">
                <tr>
                    <th class="px-4 py-2.5 font-medium">التصنيف</th>
                    <th class="px-3 py-2.5 font-medium">إعلانات</th>
                    <th class="px-3 py-2.5 font-medium">خصائص</th>
                    <th class="px-3 py-2.5 font-medium">الحالة</th>
                    <th class="px-3 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($categories as $category)
                    <tr x-data="{ editing: false }" class="align-top">
                        <td class="px-4 py-3">
                            <div @class(['font-medium' => $category->parent_id === null, 'ps-5 text-muted' => $category->parent_id !== null])>
                                {{ $category->parent_id === null ? '' : '↳ ' }}{{ $category->name_ar }}
                            </div>
                            <div class="mt-0.5 text-xs text-muted" dir="ltr">{{ $category->slug }}</div>

                            <form x-show="editing" x-cloak method="POST"
                                  action="{{ route('admin.categories.update', $category) }}"
                                  class="mt-3 space-y-2 rounded-xl border border-line bg-tint/30 p-3">
                                @csrf
                                <input name="name_ar" value="{{ $category->name_ar }}" required maxlength="120"
                                       class="w-full rounded-lg border border-line px-2.5 py-1.5 text-sm">
                                <input name="slug" value="{{ $category->slug }}" required dir="ltr"
                                       class="w-full rounded-lg border border-line px-2.5 py-1.5 text-sm">
                                <div class="grid grid-cols-2 gap-2">
                                    <input name="icon" value="{{ $category->icon }}" placeholder="أيقونة" dir="ltr"
                                           class="rounded-lg border border-line px-2.5 py-1.5 text-sm">
                                    <input name="sort_order" type="number" value="{{ $category->sort_order }}" min="0"
                                           class="rounded-lg border border-line px-2.5 py-1.5 text-sm">
                                </div>
                                <label class="flex items-center gap-2 text-xs">
                                    <input type="checkbox" name="is_active" value="1" @checked($category->is_active)
                                           class="size-4 rounded border-line">
                                    <span>مفعّل</span>
                                </label>
                                <button class="rounded-lg bg-ink px-3 py-1.5 text-xs font-semibold text-white">حفظ</button>
                            </form>
                        </td>

                        <td class="px-3 py-3 tabular-nums text-muted">{{ $category->listings_count }}</td>
                        <td class="px-3 py-3 tabular-nums text-muted">{{ $category->attributes_count }}</td>

                        <td class="px-3 py-3">
                            @if ($category->is_active)
                                <span class="rounded-md bg-green-50 px-2 py-0.5 text-xs text-green-800">مفعّل</span>
                            @else
                                <span class="rounded-md bg-tint px-2 py-0.5 text-xs text-muted">معطّل</span>
                            @endif
                        </td>

                        <td class="px-3 py-3 text-left">
                            <button type="button" @click="editing = ! editing"
                                    class="rounded-lg border border-line px-2.5 py-1 text-xs font-medium transition hover:bg-tint">
                                تعديل
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ═══ إنشاء ═══ --}}
    <form method="POST" action="{{ route('admin.categories.store') }}"
          class="h-fit space-y-3 rounded-2xl border border-line bg-white p-5">
        @csrf
        <h2 class="text-sm font-semibold">تصنيف جديد</h2>

        <div>
            <label for="new-name" class="block text-xs font-medium">الاسم <span class="text-red-600">*</span></label>
            <input id="new-name" name="name_ar" value="{{ old('name_ar') }}" required maxlength="120"
                   class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
        </div>

        <div>
            <label for="new-slug" class="block text-xs font-medium">المُعرِّف <span class="text-red-600">*</span></label>
            <input id="new-slug" name="slug" value="{{ old('slug') }}" required dir="ltr" placeholder="cars-used"
                   class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-muted">يظهر في الرابط — حروف لاتينية صغيرة وأرقام وشرطة.</p>
        </div>

        <div>
            <label for="new-parent" class="block text-xs font-medium">التصنيف الأب</label>
            <select id="new-parent" name="parent_id"
                    class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm">
                <option value="">— تصنيف رئيسي —</option>
                @foreach ($roots as $root)
                    <option value="{{ $root->id }}" @selected(old('parent_id') == $root->id)>{{ $root->name_ar }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-muted">لا يُنقل بعد الإنشاء — النقل يُعيد حساب مسار كل الفروع.</p>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label for="new-icon" class="block text-xs font-medium">أيقونة</label>
                <input id="new-icon" name="icon" value="{{ old('icon') }}" dir="ltr"
                       class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
            </div>
            <div>
                <label for="new-sort" class="block text-xs font-medium">الترتيب</label>
                <input id="new-sort" name="sort_order" type="number" min="0" value="{{ old('sort_order', 0) }}"
                       class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))
                   class="size-4 rounded border-line">
            <span>مفعّل</span>
        </label>

        <button class="w-full rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white">أنشئ التصنيف</button>

        <p class="text-xs text-muted">
            لا حذف: التعطيل يُخفي التصنيف من التصفّح والنشر ويُبقي إعلاناته. والحذف يمسح الأبناء وقيم الخصائص معه.
        </p>
    </form>
</div>

@endsection
