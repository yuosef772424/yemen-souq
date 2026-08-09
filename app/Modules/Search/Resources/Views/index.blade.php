@extends('shared::layouts.app')

@section('title', $criteria->hasQuery() ? "نتائج البحث عن {$criteria->query}" : 'تصفح الإعلانات')

@php $matchMode = $matchMode ?? 'browse'; @endphp

@section('content')

<form method="GET" action="{{ route('search') }}"
      x-data="priceFilter(@js($rateMap), @js($criteria->priceCurrency), @js($baseCurrency->symbol))">

    {{-- شريط البحث النصي --}}
    <div class="flex gap-2">
        <label class="sr-only" for="q">كلمة البحث</label>
        <input id="q" name="q" type="search" value="{{ $criteria->query }}"
               maxlength="{{ \App\Modules\Search\DTO\SearchCriteria::MAX_QUERY_LENGTH }}"
               placeholder="ابحث عن سيارة، شقة، جوال…"
               class="min-w-0 flex-1 rounded-xl border border-line bg-white px-4 py-3 text-base placeholder:text-muted focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">

        <button type="submit"
                class="rounded-xl bg-ink px-5 py-3 text-sm font-semibold text-white transition hover:bg-black">
            ابحث
        </button>
    </div>

    <div class="mt-5 grid gap-6 lg:grid-cols-[280px_1fr]">

        {{-- ═══ الفلاتر ═══ --}}
        <aside>
            <button type="button" @click="$refs.panel.classList.toggle('hidden')"
                    class="mb-3 w-full rounded-xl border border-line bg-white px-4 py-3 text-start text-sm font-semibold lg:hidden">
                تصفية النتائج
            </button>

            <div x-ref="panel" class="hidden space-y-5 lg:block">
                @include('search::partials.filters')
            </div>
        </aside>

        {{-- ═══ النتائج ═══ --}}
        <section>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3">
                <p class="text-sm text-muted">
                    <span class="font-semibold text-ink">{{ number_format($listings->total()) }}</span> إعلان
                    @if ($criteria->hasPriceFilter())
                        <span class="text-ink">·</span>
                        <span>ضمن نطاقك السعري بعد توحيد العملات</span>
                    @endif
                </p>

                <label class="flex items-center gap-2 text-sm">
                    <span class="text-muted">الترتيب</span>
                    <select name="sort" onchange="this.form.submit()"
                            class="rounded-lg border border-line bg-white py-1.5 pe-8 ps-2 text-sm focus:border-brand focus:outline-none">
                        <option value="newest" @selected($criteria->sort === 'newest')>الأحدث</option>
                        <option value="oldest" @selected($criteria->sort === 'oldest')>الأقدم</option>
                        <option value="price_asc" @selected($criteria->sort === 'price_asc')>الأقل قيمة</option>
                        <option value="price_desc" @selected($criteria->sort === 'price_desc')>الأعلى قيمة</option>
                    </select>
                </label>
            </div>

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
                <ul class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($listings as $i => $listing)
                        @include('search::partials.listing-card', [
                            'listing' => $listing,
                            'baseCurrency' => $baseCurrency,
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
</form>

@endsection