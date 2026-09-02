

{{-- عملة الإعلان: لا فلتر ثابت هنا. الفلترة بعملة واحدة لا تنفع إلا حين
     تختلط النتائج فعلياً — والقرار الأصوب أن يظهر خيار التضييق عند مكان
     ظهور الاختلاط نفسه (شريط توزيع العملات في index.blade.php)، لا مكاناً
     ثابتاً يُحمَّل مع كل صفحة سواء وُجد اختلاط أم لا --}}

{{--
    ═══ الأقسام: تنقّل هرمي لا قائمة مسطّحة ═══

    كانت `<select>` واحدة تحمل أربعين خياراً — جدارٌ يُتخطّى كله، ويدفن
    تحته «مواصفات التصنيف» وهي أدقّ الفلاتر وأنفعها (سعة التخزين، سنة
    الصنع، الشركة المصنعة). والهرمي يعرض مستوىً واحداً في كل مرة مع طريق
    عودة واضح — نفس ما تفعله منصات الإعلانات المبوّبة.

    وروابط لا حقول: اختيار القسم **تنقّلٌ** لا تنقيح، لأنه يغيّر ما تعنيه
    بقية الفلاتر (المواصفات المعروضة تتبدّل معه). وتأجيله حتى «طبّق»
    يعني عرض مواصفات قسمٍ غير الذي يظنّه المستخدم مختاراً.

    والقسم يُحمَل في حقل خفي كي لا تُسقطه بقية الفلاتر عند تطبيقها.
--}}
@php
    // أبناء ما يُتصفَّح الآن: أبناء الجذر المفتوح، أو إخوة الفرع المفتوح
    $branchParent = $parentCategory ?? $activeCategory;
    $branchChildren = $branchParent?->children ?? collect();
@endphp

<fieldset class="rounded-2xl border border-line bg-white p-4">
    <legend class="px-1 text-sm font-semibold">القسم</legend>

    <input type="hidden" name="category" value="{{ $criteria->categoryId }}">

    @if ($branchParent === null)
        {{-- لا قسم مفتوح: الجذور وحدها --}}
        <ul class="mt-2 space-y-0.5">
            @foreach ($categories as $root)
                <li>
                    <a href="{{ route('search', [...request()->except(['page', 'attr', 'category']), 'category' => $root->id]) }}"
                       class="flex items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-sm transition hover:bg-tint">
                        <span class="flex min-w-0 items-center gap-2">
                            @include('shared::partials.category-icon', ['icon' => $root->icon, 'class' => 'size-4 shrink-0 text-brand'])
                            <span class="truncate">{{ $root->name_ar }}</span>
                        </span>
                        <svg class="size-3.5 shrink-0 text-muted rtl:rotate-180" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                            <path d="m9 18 6-6-6-6"/>
                        </svg>
                    </a>
                </li>
            @endforeach
        </ul>
    @else
        {{-- طريق العودة أولاً: بلاه يصير التعمّق طريقاً بلا رجعة --}}
        <a href="{{ route('search', request()->except(['page', 'attr', 'category'])) }}"
           class="mt-2 flex items-center gap-1.5 text-xs font-medium text-muted transition hover:text-ink">
            <svg class="size-3.5 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            كل الأقسام
        </a>

        <a href="{{ route('search', [...request()->except(['page', 'attr', 'category']), 'category' => $branchParent->id]) }}"
           @class([
               'mt-2 flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm font-semibold transition',
               'bg-brand/10 text-brand-dark' => $criteria->categoryId === $branchParent->id,
               'hover:bg-tint' => $criteria->categoryId !== $branchParent->id,
           ])>
            @include('shared::partials.category-icon', ['icon' => $branchParent->icon, 'class' => 'size-4 shrink-0 text-brand'])
            <span class="truncate">كل {{ $branchParent->name_ar }}</span>
        </a>

        @if ($branchChildren->isNotEmpty())
            <ul class="mt-1 space-y-0.5 border-s border-line ps-3">
                @foreach ($branchChildren as $child)
                    <li>
                        <a href="{{ route('search', [...request()->except(['page', 'attr', 'category']), 'category' => $child->id]) }}"
                           @class([
                               'block truncate rounded-lg px-2.5 py-1.5 text-sm transition',
                               'bg-brand/10 font-semibold text-brand-dark' => $criteria->categoryId === $child->id,
                               'text-muted hover:bg-tint hover:text-ink' => $criteria->categoryId !== $child->id,
                           ])>
                            {{ $child->name_ar }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</fieldset>

{{-- المواصفات مباشرة تحت القسم وفوق السعر: هي أدقّ ما يملكه المستخدم
     للتضييق («سعة التخزين» أنفع من نطاق سعري في قسم الجوالات)، ودفنها
     أسفل اللوحة كان يجعلها غير مكتشَفة أصلاً --}}
@include('search::partials.attribute-filters')

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
