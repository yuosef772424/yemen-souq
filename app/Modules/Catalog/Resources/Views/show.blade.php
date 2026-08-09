@extends('shared::layouts.app')

@section('title', $listing->title)
@section('description', Str::limit($listing->description ?? $listing->title, 150))

@section('content')

<nav aria-label="مسار التصفح" class="mb-4 text-xs text-muted">
    <ol class="flex flex-wrap items-center gap-1.5">
        <li><a href="{{ route('search') }}" class="hover:text-ink hover:underline">الرئيسية</a></li>
        @if ($listing->category?->parent)
            <li aria-hidden="true">/</li>
            <li>
                <a href="{{ route('search', ['category' => $listing->category->parent->id]) }}"
                   class="hover:text-ink hover:underline">{{ $listing->category->parent->name_ar }}</a>
            </li>
        @endif
        @if ($listing->category)
            <li aria-hidden="true">/</li>
            <li>
                <a href="{{ route('search', ['category' => $listing->category->id]) }}"
                   class="hover:text-ink hover:underline">{{ $listing->category->name_ar }}</a>
            </li>
        @endif
    </ol>
</nav>

<div class="grid gap-6 lg:grid-cols-[1fr_340px]">

    <div class="space-y-5">

        {{-- معرض الصور --}}
        <div x-data="{ active: 0 }" class="overflow-hidden rounded-2xl border border-line bg-white">
            <div class="aspect-[16/10] bg-tint">
                @forelse ($listing->images as $index => $image)
                    <img @if ($index > 0) x-show="active === {{ $index }}" x-cloak
                         @else x-show="active === 0" @endif
                         src="{{ asset('storage/' . $image->path) }}"
                         alt="{{ $listing->title }} — صورة {{ $index + 1 }}"
                         class="size-full object-cover">
                @empty
                    <div class="grid size-full place-items-center text-sm text-muted">لا توجد صور لهذا الإعلان</div>
                @endforelse
            </div>

            @if ($listing->images->count() > 1)
                <div class="flex gap-2 overflow-x-auto p-3">
                    @foreach ($listing->images as $index => $image)
                        <button type="button" @click="active = {{ $index }}"
                                :class="active === {{ $index }} ? 'ring-2 ring-brand' : 'ring-1 ring-line'"
                                class="size-16 shrink-0 overflow-hidden rounded-lg"
                                aria-label="الصورة {{ $index + 1 }}">
                            <img src="{{ asset('storage/' . $image->path) }}" alt="" class="size-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- العنوان والتفاصيل --}}
        <div class="rounded-2xl border border-line bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h1 class="text-xl font-bold leading-snug">{{ $listing->title }}</h1>
                @if ($listing->is_featured)
                    <span class="rounded-md bg-ink px-2 py-0.5 text-[11px] font-semibold text-white">مميّز</span>
                @endif
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                @if ($listing->condition_type)
                    <div>
                        <dt class="text-xs text-muted">الحالة</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ ['new' => 'جديد', 'like_new' => 'كالجديد', 'used' => 'مستعمل', 'for_parts' => 'قطع غيار'][$listing->condition_type] ?? '—' }}
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs text-muted">الموقع</dt>
                    <dd class="mt-0.5 font-medium">{{ $listing->location?->name_ar }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">النشر</dt>
                    <dd class="mt-0.5 font-medium">{{ $listing->published_at?->diffForHumans() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">المشاهدات</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">{{ number_format($listing->views_count) }}</dd>
                </div>
            </dl>

            @if ($listing->description)
                <div class="mt-5 border-t border-line pt-5">
                    <h2 class="text-sm font-semibold">الوصف</h2>
                    <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink/85">{{ $listing->description }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- العمود الجانبي --}}
    <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">

        <div class="rounded-2xl border border-line bg-white p-5">
            <p class="text-2xl font-bold tabular-nums text-brand-dark">{{ $listing->formattedPrice() }}</p>

            @if ($listing->is_negotiable)
                <p class="mt-1 text-xs text-muted">قابل للتفاوض</p>
            @endif

            @if ($equivalents !== [])
                <div class="mt-4 border-t border-line pt-4">
                    <p class="text-xs font-semibold text-muted">يقابل تقريباً</p>
                    <ul class="mt-2 space-y-1.5 text-sm tabular-nums">
                        @foreach ($equivalents as $equivalent)
                            <li class="flex items-baseline justify-between gap-3">
                                <span class="text-muted">{{ $equivalent['currency']->name_ar }}</span>
                                <span class="font-medium">{{ $equivalent['currency']->format($equivalent['amount']) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($listing->rate_date)
                        <p class="mt-3 text-[11px] leading-relaxed text-muted">
                            محسوب بسعر صرف {{ $listing->rate_date->translatedFormat('j F Y') }}.
                            السعر المعتمد هو ما حدده المعلن بعملته الأصلية.
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-line bg-white p-5">
            <p class="text-sm font-semibold">{{ $listing->user?->name ?? 'معلن' }}</p>
            <p class="mt-0.5 text-xs text-muted">عضو منذ {{ $listing->user?->created_at?->translatedFormat('F Y') }}</p>

            @if ($listing->contact_phone && ! $listing->hide_phone)
                <div x-data="contactReveal({{ $listing->id }})" class="mt-4">
                    <button type="button" x-show="!phone" :disabled="loading" @click="reveal()"
                            class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:opacity-60">
                        <span x-text="loading ? 'جارٍ الجلب…' : 'إظهار رقم التواصل'"></span>
                    </button>

                    <a x-show="phone" x-cloak :href="'tel:' + phone" x-text="phone"
                       class="block w-full rounded-xl border border-brand px-4 py-3 text-center text-sm font-semibold tabular-nums text-brand-dark"></a>

                    <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs text-red-700"></p>
                </div>
            @endif

            <p class="mt-3 text-[11px] leading-relaxed text-muted">
                افحص المنتج قبل الدفع، ولا تحوّل مبالغ مقدماً لمن لا تعرفه.
            </p>
        </div>
    </aside>
</div>

@if (count($similar) > 0)
    <section class="mt-10">
        <h2 class="text-base font-semibold">إعلانات مشابهة في القيمة</h2>
        <p class="mt-1 text-xs text-muted">المقارنة بالقيمة الموحّدة، لا بالرقم المكتوب</p>

        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($similar as $item)
                @include('search::partials.listing-card', ['listing' => $item, 'baseCurrency' => $baseCurrency])
            @endforeach
        </ul>
    </section>
@endif

@endsection
