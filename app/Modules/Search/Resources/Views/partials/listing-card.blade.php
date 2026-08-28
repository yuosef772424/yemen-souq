@php
    // معاملات تتبّع النقرة: تُسجَّل عند وصول الزائر لصفحة التفاصيل.
    // $position و$queryNormalized يمرّرهما include من صفحة النتائج؛
    // في السياقات الأخرى (إعلانات مشابهة) يبقيان فارغين.
    $trackingParams = array_filter([
        'from' => $position ?? null ? 'search' : null,
        'pos' => $position ?? null,
        'q' => $queryNormalized ?? null,
    ]);
@endphp

<li class="group relative flex flex-col overflow-hidden rounded-2xl border border-line bg-white transition hover:border-ink/25 hover:shadow-sm">

    @if ($listing->is_featured)
        <span class="absolute end-3 top-3 z-10 rounded-md bg-ink px-2 py-0.5 text-[11px] font-semibold text-white">
            مميّز
        </span>
    @endif

    <div class="aspect-[4/3] bg-tint">
        @if ($listing->images->isNotEmpty())
            <img src="{{ asset('storage/' . ($listing->images->first()->thumb_path ?? $listing->images->first()->path)) }}"
                 alt="{{ $listing->title }}" loading="lazy"
                 class="size-full object-cover">
        @else
            <div class="grid size-full place-items-center text-xs text-muted">لا توجد صورة</div>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-3">
        <h3 class="line-clamp-2 text-sm font-semibold leading-snug">
            <a href="{{ route('listing.show', [$listing, ...$trackingParams]) }}" class="hover:underline">
                {{ $listing->title }}
            </a>
        </h3>

        {{-- السعر: الأصلي بارزاً كما أدخله المعلن، والقيمة الموحّدة تحته --}}
        <div class="mt-2">
            <p class="text-base font-bold tabular-nums text-brand-dark">
                {{ $listing->formattedPrice() }}
            </p>

            @if ($listing->price_in_base !== null && $listing->currency_code !== $baseCurrency->code)
                <p class="mt-0.5 text-xs tabular-nums text-muted">
                    ≈ {{ number_format((float) $listing->price_in_base) }} {{ $baseCurrency->symbol }}
                </p>
            @endif
        </div>

        <div class="mt-auto pt-2.5">
            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted">
                <span>{{ $listing->location?->name_ar }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ $listing->category?->name_ar }}</span>
            </p>
            <p class="mt-1 text-xs text-muted">
                {{ $listing->published_at?->diffForHumans() }}
            </p>
        </div>
    </div>
</li>
