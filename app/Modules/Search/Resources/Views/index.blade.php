@extends('shared::layouts.app')

@section('title', $criteria->hasQuery() ? "نتائج البحث عن {$criteria->query}" : 'تصفح الإعلانات')

@php
    $matchMode = $matchMode ?? 'browse';
    $activeFilterCount = count($criteria->activeFilterKeys());
    $showRecentlyViewed = ! $criteria->hasQuery() && $activeFilterCount === 0;

    // التصنيف المفتوح وأبوه وإخوته — مشتقّة من $categories المحمَّلة أصلاً
    // (جذور بأبنائها)، فلا استعلام إضافي لبناء المسار ولا للشرائح
    $activeCategory = null;
    $parentCategory = null;

    foreach ($categories as $root) {
        if ($root->id === $criteria->categoryId) {
            $activeCategory = $root;
            break;
        }

        foreach ($root->children as $child) {
            if ($child->id === $criteria->categoryId) {
                $activeCategory = $child;
                $parentCategory = $root;
                break 2;
            }
        }
    }

    // جذرٌ مفتوح يعرض أبناءه للتعمّق، وفرعٌ مفتوح يعرض إخوته للانتقال جانباً
    $siblings = $parentCategory?->children ?? $activeCategory?->children ?? collect();
    $activeLocationName = $criteria->locationId
        ? $governorates->firstWhere('id', $criteria->locationId)?->name_ar
        : null;

    $heading = $criteria->hasQuery()
        ? "نتائج البحث عن «{$criteria->query}»"
        : ($activeCategory->name_ar ?? 'كل الإعلانات') . ' في ' . ($activeLocationName ?? 'اليمن');
@endphp

@section('content')

{{-- ═══ مسار التصفّح ═══ --}}
<nav aria-label="مسار التصفّح" class="flex flex-wrap items-center gap-1.5 text-xs text-muted">
    <a href="{{ route('search') }}" class="transition hover:text-ink">اليمن</a>

    @if ($parentCategory)
        <span aria-hidden="true">›</span>
        <a href="{{ route('search', ['category' => $parentCategory->id]) }}" class="transition hover:text-ink">
            {{ $parentCategory->name_ar }}
        </a>
    @endif

    @if ($activeCategory)
        <span aria-hidden="true">›</span>
        <span class="font-medium text-ink">{{ $activeCategory->name_ar }}</span>
    @endif
</nav>

{{-- ═══ العنوان: يحمل ما يبحث عنه الزائر وأين وكم — لا عدّاداً منفصلاً ═══ --}}
<h1 class="mt-2 text-xl font-bold sm:text-2xl">
    {{ $heading }}
    {{-- «+» لا رقمٌ دقيق عند بلوغ السقف: العدّ يتوقف عند ألف، فادّعاء رقمٍ
         أكبر ادّعاءُ ما لم يُحسب. انظر BoundedPaginator --}}
    <span class="tabular-nums font-semibold text-muted">({{ number_format($listings->total()) }}{{ $listings->isCapped() ? '+' : '' }})</span>
</h1>

{{-- ═══ شرائح الأقسام الفرعية: التعمّق بنقرة بلا فتح لوحة الفلاتر ═══ --}}
@if ($siblings->isNotEmpty())
    <div class="-mx-4 mt-3 flex gap-2 overflow-x-auto px-4 pb-1">
        @foreach ($siblings as $sibling)
            <a href="{{ route('search', [...request()->except(['page', 'attr']), 'category' => $sibling->id]) }}"
               @class([
                   'shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium transition',
                   'border-brand bg-brand/5 text-brand-dark' => $criteria->categoryId === $sibling->id,
                   'border-line bg-white text-ink hover:border-ink/30' => $criteria->categoryId !== $sibling->id,
               ])>
                {{ $sibling->name_ar }}
            </a>
        @endforeach
    </div>
@endif

{{-- شوهدت مؤخراً: يظهر فقط عند التصفح الحر، لا داخل نتائج مفلترة — نفس عرف
     أمازون وشي إن في إبقاء هذا الشريط للصفحة العامة لا نتائج بحث نشطة --}}
@if ($showRecentlyViewed)
    <div x-data="recentlyViewed()" x-show="items.length > 0" x-cloak class="mt-5">
        <h2 class="mb-2 text-sm font-semibold text-ink">شوهدت مؤخراً</h2>
        <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-1">
            <template x-for="item in items" :key="item.id">
                <a :href="item.url"
                   class="block w-32 shrink-0 rounded-xl border border-line bg-white p-2 transition hover:border-ink/25">
                    <div class="aspect-square overflow-hidden rounded-lg bg-tint">
                        <img :src="item.image" :alt="item.title" x-show="item.image" class="size-full object-cover">
                    </div>
                    <p class="mt-1.5 line-clamp-2 text-xs font-medium leading-snug" x-text="item.title"></p>
                    <p class="mt-0.5 text-xs font-bold tabular-nums text-brand-dark" x-text="item.price"></p>
                </a>
            </template>
        </div>
    </div>
@endif

{{--
    نموذج الفلاتر يلفّ اللوحة الجانبية وحدها، لا الصفحة.

    كان يلفّ كل شيء بما فيه قائمة النتائج — وزرّ المفضلة في البطاقة نموذج
    POST، ونموذجٌ داخل نموذج HTML غير صالح يتجاهله المتصفح بلا إنذار.
    الفرز باقٍ خارج اللوحة ويصل إليها بالسمة `form`.
--}}
<div class="mt-5 grid gap-6 lg:grid-cols-[280px_1fr]" x-data="{ open: false }">

    {{-- ═══ الفلاتر ═══ --}}
    <aside>
        {{-- خلفية معتّمة: تُغلق اللوحة عند النقر خارجها، على الجوال فقط --}}
        <div x-show="open" x-cloak x-transition.opacity @click="open = false"
             class="fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

        {{-- ورقة سفلية على الجوال، شريط جانبي ثابت على سطح المكتب —
             نفس عنصر DOM الواحد، لا تكرار لحقول النموذج --}}
        <form method="GET" action="{{ route('search') }}" id="search-filters"
              x-data="priceFilter(@js($rateMap), @js($criteria->priceCurrency), @js($baseCurrency->symbol))"
              x-show="open" x-cloak
              x-transition:enter="transition ease-out duration-300"
              x-transition:enter-start="opacity-0 translate-y-full"
              x-transition:enter-end="opacity-100 translate-y-0"
              x-transition:leave="transition ease-in duration-200"
              x-transition:leave-start="opacity-100 translate-y-0"
              x-transition:leave-end="opacity-0 translate-y-full"
              class="fixed inset-x-0 bottom-0 z-50 max-h-[85vh] space-y-5 overflow-y-auto rounded-t-2xl bg-white p-4 shadow-2xl lg:!static lg:!z-auto lg:!block lg:max-h-none lg:overflow-visible lg:rounded-2xl lg:bg-transparent lg:p-0 lg:shadow-none">

            {{-- كلمة البحث تُكتب في ترويسة الموقع، وتُحمل هنا خفيّةً كي لا
                 يُسقطها تطبيق فلتر: من فلتر نتائج كلمةٍ يقصد تضييقها لا مسحها --}}
            <input type="hidden" name="q" value="{{ $criteria->query }}">

            <div class="mb-1 flex items-center justify-between lg:hidden">
                <h2 class="text-base font-semibold">الفلاتر</h2>
                <button type="button" @click="open = false"
                        class="rounded-lg p-2 text-muted transition hover:bg-tint" aria-label="إغلاق">
                    ✕
                </button>
            </div>

            @include('search::partials.filters')
        </form>
    </aside>

    {{-- ═══ النتائج ═══ --}}
    <section>
        {{-- شريط موحّد: عدّاد النتائج + زر التصفية (جوال) + الفرز، لاصق أثناء
             التمرير كما في منصات التسوّق الكبرى --}}
        <div class="sticky top-[104px] z-20 -mx-4 flex flex-wrap items-center justify-between gap-3 border-b border-line bg-canvas/95 px-4 py-3 backdrop-blur sm:mx-0 sm:px-0">
            <p class="text-sm text-muted">
                <span class="font-semibold text-ink">{{ $listings->isCapped() ? 'أكثر من '.number_format($listings->total()) : number_format($listings->total()) }}</span> إعلان
                @if ($criteria->hasPriceFilter())
                    <span class="text-ink">·</span>
                    <span>ضمن نطاقك السعري بعد توحيد العملات</span>
                @endif
            </p>

            <div class="flex items-center gap-2">
                <button type="button" @click="open = true"
                        class="flex items-center gap-1.5 rounded-lg border border-line bg-white px-3 py-1.5 text-sm font-medium transition hover:bg-tint lg:hidden">
                    <span>تصفية</span>
                    @if ($activeFilterCount > 0)
                        <span class="grid size-4 place-items-center rounded-full bg-brand text-[10px] font-semibold text-white">
                            {{ $activeFilterCount }}
                        </span>
                    @endif
                </button>

                {{-- السمة `form` تربط الفرز بلوحة الفلاتر رغم وقوعه خارجها --}}
                <label class="flex items-center gap-2 rounded-lg border border-line bg-white py-1.5 pe-8 ps-3 text-sm">
                    <span class="text-muted">الترتيب</span>
                    <select name="sort" form="search-filters" onchange="this.form.submit()"
                            class="border-0 bg-transparent p-0 pe-1 text-sm font-medium text-ink focus:outline-none focus:ring-0">
                        <option value="newest" @selected($criteria->sort === 'newest')>الأحدث</option>
                        <option value="oldest" @selected($criteria->sort === 'oldest')>الأقدم</option>
                        <option value="price_asc" @selected($criteria->sort === 'price_asc')>الأقل قيمة</option>
                        <option value="price_desc" @selected($criteria->sort === 'price_desc')>الأعلى قيمة</option>
                    </select>
                </label>
            </div>
        </div>

        @include('search::partials.active-filters')

        {{--
            التراجع يُعلَن، ولا يُخفى.

            عرض نتائج «أقرب» كما لو كانت مطابقة تامة يُربك المستخدم:
            يرى إعلانات لا تحمل كل كلماته فيحسب المحرك مكسوراً. الجملة
            الواحدة هنا تحوّل الشعور من «البحث لا يفهمني» إلى «لا يوجد
            ما طلبته بالضبط، وهذه البدائل».
        --}}
        @if ($matchMode === 'near')
            <p role="status" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                لا يوجد إعلان يطابق كل كلماتك. هذه أقرب النتائج، مرتّبة بعدد الكلمات المطابقة.
            </p>
        @endif

        {{-- توزيع العملات: يُظهر للمستخدم أن النتائج مختلطة العملات بدل أن يفاجأ --}}
        @if (count($breakdown) > 1)
            <div class="mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-tint p-3 text-xs">
                <span class="font-semibold text-ink">النتائج معروضة بعملات مختلفة:</span>
                @foreach ($breakdown as $code => $total)
                    @php $cur = $currencies->firstWhere('code', $code); @endphp
                    <span class="rounded-md bg-white px-2 py-1 font-medium text-muted ring-1 ring-line">
                        {{ $cur?->name_ar ?? $code }} — {{ number_format($total) }}
                    </span>
                @endforeach
                <span class="w-full text-muted">القيم موحّدة داخلياً بـ{{ $baseCurrency->name_ar }} للمقارنة والترتيب.</span>
            </div>
        @endif

        @if ($listings->isEmpty())
            <div class="mt-10 rounded-2xl border border-dashed border-line bg-white px-6 py-14 text-center">
                <p class="text-base font-semibold">لا توجد إعلانات مطابقة</p>

                @if ($matchMode === 'none')
                    {{-- المستخدم يستحق أن يعرف أننا لم نكتفِ بمحاولة واحدة --}}
                    <p class="mt-2 text-sm text-muted">
                        جرّبنا كلماتك مجتمعةً، ثم كل كلمة على حدة ومعها مكافئاتها — بلا نتيجة.
                    </p>
                @endif

                <p class="mt-2 text-sm text-muted">
                    وسّع النطاق السعري، أو أزل بعض الفلاتر، أو جرّب كلمة أعم.
                </p>
                <a href="{{ route('search') }}"
                   class="mt-5 inline-block rounded-lg border border-line px-4 py-2 text-sm font-medium transition hover:bg-tint">
                    إزالة كل الفلاتر
                </a>
            </div>
        @else
            <ul class="mt-4 space-y-3">
                @foreach ($listings as $i => $listing)
                    @include('search::partials.listing-card', [
                        'listing' => $listing,
                        'baseCurrency' => $baseCurrency,
                        'favoritedIds' => $favoritedIds,
                        'position' => ($listings->firstItem() ?? 1) + $i,
                        'queryNormalized' => $criteria->query,
                    ])
                @endforeach
            </ul>

            <div class="mt-8">
                {{ $listings->links() }}
            </div>
        @endif
    </section>
</div>

@endsection
