{{-- ═══ السعر: العنصر المميّز في هذه الصفحة ═══ --}}
<fieldset class="rounded-2xl border border-line bg-white p-4">
    <legend class="px-1 text-sm font-semibold">السعر</legend>

    <label for="currency" class="mt-1 block text-xs text-muted">أدخل النطاق بالعملة التي تفكر بها</label>
    <select id="currency" name="currency" x-model="currency"
            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
        @foreach ($currencies as $currency)
            <option value="{{ $currency->code }}">{{ $currency->name_ar }} ({{ $currency->symbol }})</option>
        @endforeach
    </select>

    <div class="mt-3 grid grid-cols-2 gap-2">
        <div>
            <label for="min" class="block text-xs text-muted">من</label>
            <input id="min" name="min" type="number" min="0" step="any" inputmode="decimal"
                   x-model="min" value="{{ $criteria->priceMin }}"
                   class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm tabular-nums focus:border-brand focus:outline-none">
        </div>
        <div>
            <label for="max" class="block text-xs text-muted">إلى</label>
            <input id="max" name="max" type="number" min="0" step="any" inputmode="decimal"
                   x-model="max" value="{{ $criteria->priceMax }}"
                   class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm tabular-nums focus:border-brand focus:outline-none">
        </div>
    </div>

    {{-- المعاينة الحية: هذه هي الفكرة كلها في سطر واحد مرئي --}}
    <p x-show="preview" x-cloak x-text="preview"
       class="mt-3 rounded-lg bg-tint px-3 py-2 text-xs leading-relaxed text-ink tabular-nums"></p>

    <p class="mt-2 text-xs text-muted">
        النتائج تشمل الإعلانات بكل العملات إذا تساوت قيمتها الفعلية — لا الأرقام المتشابهة فقط.
    </p>
</fieldset>

{{-- عملة الإعلان: لا فلتر ثابت هنا. الفلترة بعملة واحدة لا تنفع إلا حين
     تختلط النتائج فعلياً — والقرار الأصوب أن يظهر خيار التضييق عند مكان
     ظهور الاختلاط نفسه (شريط توزيع العملات في index.blade.php)، لا مكاناً
     ثابتاً يُحمَّل مع كل صفحة سواء وُجد اختلاط أم لا --}}

{{-- ═══ التصنيف ═══ --}}
<fieldset class="rounded-2xl border border-line bg-white p-4">
    <legend class="px-1 text-sm font-semibold">التصنيف</legend>

    <select name="category"
            class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
        <option value="">كل التصنيفات</option>
        @foreach ($categories as $category)
            <optgroup label="{{ $category->name_ar }}">
                <option value="{{ $category->id }}" @selected($criteria->categoryId === $category->id)>
                    كل {{ $category->name_ar }}
                </option>
                @foreach ($category->children as $child)
                    <option value="{{ $child->id }}" @selected($criteria->categoryId === $child->id)>
                        {{ $child->name_ar }}
                    </option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
</fieldset>

{{-- ═══ مواصفات التصنيف — بعد التصنيف مباشرة لأنها تابعة له ═══ --}}
@include('search::partials.attribute-filters')

{{-- ═══ الموقع ═══ --}}
<fieldset class="rounded-2xl border border-line bg-white p-4">
    <legend class="px-1 text-sm font-semibold">المحافظة</legend>

    <select name="location"
            class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
        <option value="">كل المحافظات</option>
        @foreach ($governorates as $governorate)
            <option value="{{ $governorate->id }}" @selected($criteria->locationId === $governorate->id)>
                {{ $governorate->name_ar }}
            </option>
        @endforeach
    </select>
</fieldset>

{{-- ═══ الحالة ═══ --}}
<fieldset class="rounded-2xl border border-line bg-white p-4">
    <legend class="px-1 text-sm font-semibold">حالة المنتج</legend>

    <div class="mt-2 space-y-2 text-sm">
        @foreach (['' => 'الكل', 'new' => 'جديد', 'like_new' => 'كالجديد', 'used' => 'مستعمل', 'for_parts' => 'قطع غيار'] as $value => $label)
            <label class="flex items-center gap-2">
                <input type="radio" name="condition" value="{{ $value }}"
                       @checked(($criteria->condition ?? '') === $value)
                       class="size-4 border-line text-brand focus:ring-brand">
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
</fieldset>

{{-- ═══ بصور فقط: القدرة جاهزة في الخادم أصلاً، هذا فقط مفتاحها ═══ --}}
<label class="flex items-center gap-2 rounded-2xl border border-line bg-white p-4 text-sm font-medium">
    <input type="checkbox" name="with_image" value="1" @checked($criteria->withImageOnly)
           class="size-4 rounded border-line text-brand focus:ring-brand">
    <span>الإعلانات التي تحوي صورة فقط</span>
</label>

<div class="flex gap-2">
    <button type="submit"
            class="flex-1 rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
        طبّق الفلاتر
    </button>
    <a href="{{ route('search') }}"
       class="grid place-items-center rounded-xl border border-line px-4 text-sm font-medium transition hover:bg-tint">
        مسح
    </a>
</div>
