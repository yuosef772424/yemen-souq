@php
    // معاملات تتبّع النقرة: تُسجَّل عند وصول الزائر لصفحة التفاصيل.
    // $position و$queryNormalized يمرّرهما include من صفحة النتائج؛
    // في السياقات الأخرى (إعلانات مشابهة) يبقيان فارغين.
    $trackingParams = array_filter([
        'from' => $position ?? null ? 'search' : null,
        'pos' => $position ?? null,
        'q' => $queryNormalized ?? null,
    ]);

    // القلب يُرسم بحالته حين تصل القائمة، وبلا حالة حين لا تصل — البطاقة
    // تُستدعى من صفحات لا تحسبها (الإعلانات المشابهة مثلاً)
    $isFavorited = in_array($listing->id, $favoritedIds ?? [], true);
    $image = $listing->images->first();
@endphp

{{--
    بطاقة أفقية لا شبكية.

    الشبكة تعرض صورةً واسمَ وسعراً، ثم تُلزم كل من أراد رقماً بفتح صفحة
    الإعلان — وهو المسار الوحيد للتواصل في موقع بلا سلة ولا دفع. الصفّ
    الأفقي يترك عرضاً كافياً لسطر أفعال داخل البطاقة نفسها: الاتصال
    والمراسلة والحفظ بلا مغادرة النتائج، وهو ترتيب منصات الإعلانات
    المبوّبة كلها لأن الفعل هناك هو المنتج.
--}}
<li class="group relative overflow-hidden rounded-2xl border border-line bg-white transition hover:border-ink/25 hover:shadow-sm">
    <div class="flex gap-3 p-3">

        {{-- ═══ الصورة ═══ --}}
        <a href="{{ route('listing.show', [$listing, ...$trackingParams]) }}"
           class="relative block size-28 shrink-0 overflow-hidden rounded-xl bg-tint sm:size-36">
            @if ($image)
                <img src="{{ $image->thumbUrl() }}"
                     alt="{{ $listing->title }}" loading="lazy" class="size-full object-cover">

                @if ($listing->images->count() > 1)
                    <span class="absolute bottom-1.5 end-1.5 rounded-md bg-black/60 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-white">
                        {{ $listing->images->count() }} صور
                    </span>
                @endif
            @else
                <span class="grid size-full place-items-center text-xs text-muted">لا توجد صورة</span>
            @endif

            @if ($listing->is_featured)
                <span class="absolute start-1.5 top-1.5 rounded-md bg-ink px-1.5 py-0.5 text-[11px] font-semibold text-white">
                    مميّز
                </span>
            @endif
        </a>

        {{-- ═══ التفاصيل ═══ --}}
        <div class="flex min-w-0 flex-1 flex-col">
            <div class="flex items-start justify-between gap-2">
                {{-- السعر أولاً وأبرز: هو أول ما يُقرأ في الإعلانات المبوّبة،
                     والعنوان بعده — عكس ترتيب المتاجر --}}
                <div class="min-w-0">
                    <p class="text-base font-bold tabular-nums text-brand-dark sm:text-lg">
                        {{ $listing->formattedPrice() }}
                    </p>

                    @if ($listing->price_in_base !== null && $listing->currency_code !== $baseCurrency->code)
                        <p class="text-xs tabular-nums text-muted">
                            ≈ {{ number_format((float) $listing->price_in_base) }} {{ $baseCurrency->symbol }}
                        </p>
                    @endif
                </div>

                {{-- بلا `short: true`: الصيغة المختصرة تعود إنجليزيةً («منذ 2w»)
                     لأن ترجمة Carbon العربية لا تغطّيها، فتختلط اللغتان في سطر --}}
                <time class="shrink-0 text-[11px] text-muted" datetime="{{ $listing->published_at?->toIso8601String() }}">
                    {{ $listing->published_at?->diffForHumans() }}
                </time>
            </div>

            <h3 class="mt-1 line-clamp-2 text-sm font-semibold leading-snug">
                <a href="{{ route('listing.show', [$listing, ...$trackingParams]) }}"
                   class="transition hover:text-brand-dark hover:underline">
                    {{ $listing->title }}
                </a>
            </h3>

            <p class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-muted">
                <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>
                </svg>
                <span>{{ $listing->location?->name_ar }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ $listing->category?->name_ar }}</span>
            </p>

            {{-- ═══ سطر الأفعال ═══ --}}
            <div class="mt-auto flex items-center gap-2 pt-2.5">
                @if ($listing->contact_phone && ! $listing->hide_phone)
                    {{-- نفس مكوّن صفحة الإعلان: الرقم لا يُرسَل في HTML الصفحة
                         بل يُجلب عند الطلب — ولا يتغيّر ذلك بتغيّر موضع الزر --}}
                    <div x-data="contactReveal('{{ $listing->slug }}')" class="min-w-0">
                        <button type="button" x-show="!phone" :disabled="loading" @click="reveal()"
                                class="flex items-center gap-1.5 rounded-lg bg-brand px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark disabled:opacity-60">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92"/>
                            </svg>
                            <span x-text="loading ? 'جارٍ…' : 'اتصال'"></span>
                        </button>

                        <a x-show="phone" x-cloak :href="'tel:' + phone" x-text="phone"
                           class="block truncate rounded-lg border border-brand px-2.5 py-1.5 text-xs font-semibold tabular-nums text-brand-dark"></a>

                        <p x-show="error" x-cloak x-text="error" class="text-[11px] text-red-700"></p>
                    </div>
                @endif

                <a href="{{ route('listing.show', $listing) }}#contact"
                   class="flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-xs font-medium transition hover:bg-tint">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>
                    </svg>
                    راسل
                </a>

                {{-- المفضلة خارج أي نموذج GET: النماذج المتداخلة HTML غير صالح،
                     ولهذا لا تلفّ صفحة النتائج نموذج الفلاتر حول القائمة --}}
                @auth
                    <form method="POST" action="{{ route('listing.favorite', $listing) }}" class="ms-auto">
                        @csrf
                        <button type="submit" title="{{ $isFavorited ? 'إزالة من المفضلة' : 'أضف إلى المفضلة' }}"
                                @class([
                                    'grid size-8 place-items-center rounded-lg border transition',
                                    'border-brand bg-brand/5 text-brand-dark' => $isFavorited,
                                    'border-line text-muted hover:bg-tint hover:text-ink' => ! $isFavorited,
                                ])>
                            <svg class="size-4" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                                 fill="{{ $isFavorited ? 'currentColor' : 'none' }}">
                                <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
                            </svg>
                            <span class="sr-only">{{ $isFavorited ? 'إزالة من المفضلة' : 'أضف إلى المفضلة' }}</span>
                        </button>
                    </form>
                @endauth
            </div>
        </div>
    </div>
</li>
