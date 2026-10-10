@extends('shared::layouts.app')

@section('title', $listing->title)
@section('description', Str::limit($listing->description ?? $listing->title, 150))

@section('content')

@php
    $recentThumb = $listing->images->first();
    $recentViewItem = [
        'id' => $listing->id,
        'title' => $listing->title,
        'price' => $listing->formattedPrice(),
        'image' => $recentThumb ? $recentThumb->thumbUrl() : null,
        'url' => route('listing.show', $listing),
    ];
@endphp
<div x-data="rememberView(@js($recentViewItem))"></div>

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

@if ($isOwner)
    <p class="mb-4 rounded-xl border border-line bg-tint px-4 py-2.5 text-xs">
        هذه معاينة إعلانك — زياراتك له لا تُحسب في المشاهدات.
        <a href="{{ route('account.listings.edit', $listing) }}" class="font-medium text-brand-dark hover:underline">تعديل</a>
    </p>
@endif

<div class="grid gap-6 lg:grid-cols-[1fr_340px]">

    <div class="space-y-5">

        {{-- معرض الصور: يُبدَّل بالسحب أو بالمصغّرات أو بالأسهم --}}
        <div x-data="gallery" class="overflow-hidden rounded-2xl border border-line bg-white">
            <div class="relative">
                {{-- snap-x/snap-mandatory: الالتقاط عند أقرب صورة من CSS
                     لا من JS — فيبقى زخم التمرير أصلياً على الجوال --}}
                <div x-ref="track" @scroll.passive.debounce.60ms="sync()"
                     class="flex aspect-[16/10] snap-x snap-mandatory overflow-x-auto overflow-y-hidden scroll-smooth bg-tint scrollbar-none">
                    @forelse ($listing->images as $index => $image)
                        <img src="{{ $image->url() }}"
                             alt="{{ $listing->title }} — صورة {{ $index + 1 }}"
                             {{-- أول صورة eager: هي ما يراه الزائر قبل أي سحب --}}
                             loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                             draggable="false"
                             class="size-full shrink-0 snap-center object-cover">
                    @empty
                        <div class="grid size-full place-items-center text-sm text-muted">لا توجد صور لهذا الإعلان</div>
                    @endforelse
                </div>

                @if ($listing->images->count() > 1)
                    {{-- عدّاد: يقول إن وراء الصورة صوراً أخرى. وبلاه لا شيء
                         يدعو المستخدم إلى السحب أصلاً --}}
                    <div class="pointer-events-none absolute bottom-3 end-3 rounded-full bg-ink/70 px-2.5 py-1 text-xs font-medium text-white tabular-nums">
                        <span x-text="active + 1">1</span> / {{ $listing->images->count() }}
                    </div>

                    {{-- الأسهم لمن يستعمل فأرة: السحب لا مقابل له على سطح
                         المكتب، وشريط التمرير مخفيّ --}}
                    <button type="button" @click="go(Math.max(0, active - 1))"
                            x-show="active > 0" x-cloak
                            class="absolute start-2 top-1/2 hidden size-9 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow transition hover:bg-white md:grid"
                            aria-label="الصورة السابقة">
                        <svg class="size-5 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                    </button>

                    <button type="button" @click="go(Math.min({{ $listing->images->count() - 1 }}, active + 1))"
                            x-show="active < {{ $listing->images->count() - 1 }}"
                            class="absolute end-2 top-1/2 hidden size-9 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink shadow transition hover:bg-white md:grid"
                            aria-label="الصورة التالية">
                        <svg class="size-5 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                @endif
            </div>

            @if ($listing->images->count() > 1)
                <div class="flex gap-2 overflow-x-auto p-3 scrollbar-none">
                    @foreach ($listing->images as $index => $image)
                        <button type="button" @click="go({{ $index }})"
                                :class="active === {{ $index }} ? 'ring-2 ring-brand' : 'ring-1 ring-line'"
                                class="size-16 shrink-0 overflow-hidden rounded-lg transition active:scale-95"
                                aria-label="الصورة {{ $index + 1 }}">
                            <img src="{{ $image->url() }}" alt="" loading="lazy"
                                 class="size-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- العنوان والتفاصيل --}}
        <div class="rounded-2xl border border-line bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h1 class="text-xl font-bold leading-snug">{{ $listing->title }}</h1>
                @if ($listing->isFeaturedNow())
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

            {{--
                المواصفات قبل الوصف بقصد: هي إجابات محدَّدة عن أسئلة
                محدَّدة (كم الممشى؟ كم غرفة؟)، والوصف نصّ حرّ قد لا
                يذكرها. من يقرأ إعلان سيارة يبحث عن السنة والممشى أولاً.
            --}}
            @if ($attributes !== [])
                <div class="mt-5 border-t border-line pt-5">
                    <h2 class="text-sm font-semibold">المواصفات</h2>
                    <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                        @foreach ($attributes as $attribute)
                            <div class="flex items-baseline justify-between gap-3 border-b border-line/60 pb-1.5">
                                <dt class="text-sm text-muted">{{ $attribute['label'] }}</dt>
                                <dd class="text-sm font-medium tabular-nums">
                                    {{ $attribute['value'] }}
                                    @if ($attribute['unit'])
                                        <span class="text-xs font-normal text-muted">{{ $attribute['unit'] }}</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif

            @if ($listing->description)
                <div class="mt-5 border-t border-line pt-5">
                    <h2 class="text-sm font-semibold">الوصف</h2>
                    <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink/85">{{ $listing->description }}</p>
                </div>
            @endif
        </div>

        {{-- الأسئلة العامة: الوحدة تملك القالب، والصفحة تؤلّف --}}
        @include('engagement::partials.questions', [
            'listing' => $listing,
            'questions' => $questions,
            'canAsk' => $canAsk,
            'isOwner' => $isOwner,
        ])
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

            {{-- المفضلة: نموذج POST لا زرّ Alpine.
                 الحفظ يجب أن يعمل بلا JavaScript — وهو أول ما يسقط على
                 اتصال ضعيف، وهناك بالضبط يحفظ الناس ليعودوا لاحقاً --}}
            @auth
                <form method="POST" action="{{ route('listing.favorite', $listing) }}" class="mt-4">
                    @csrf
                    <button type="submit"
                            @class([
                                'w-full rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
                                'border-brand bg-brand/5 text-brand-dark' => $isFavorited,
                                'border-line hover:bg-tint' => ! $isFavorited,
                            ])>
                        {{ $isFavorited ? '★ في المفضلة' : '☆ أضف إلى المفضلة' }}
                    </button>
                </form>
            @endauth
        </div>

        <div class="rounded-2xl border border-line bg-white p-5">
            <div class="flex items-center gap-3">
                @if ($listing->user?->avatarUrl())
                    <img src="{{ $listing->user->avatarUrl() }}" alt=""
                         class="size-11 shrink-0 rounded-full object-cover">
                @else
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-canvas text-base font-bold text-muted"
                          aria-hidden="true">{{ mb_substr($listing->user?->displayName() ?? 'م', 0, 1) }}</span>
                @endif

                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold">{{ $listing->user?->displayName() ?? 'معلن' }}</p>
                    <p class="mt-0.5 text-xs text-muted">عضو منذ {{ $listing->user?->created_at?->translatedFormat('F Y') }}</p>
                </div>
            </div>

            @if ($listing->contact_phone && ! $listing->hide_phone)
                <div x-data="contactReveal('{{ $listing->slug }}')" class="mt-4">
                    <button type="button" x-show="!phone" :disabled="loading" @click="reveal()"
                            class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:opacity-60">
                        <span x-text="loading ? 'جارٍ الجلب…' : 'إظهار رقم التواصل'"></span>
                    </button>

                    <a x-show="phone" x-cloak :href="'tel:' + phone" x-text="phone"
                       class="block w-full rounded-xl border border-brand px-4 py-3 text-center text-sm font-semibold tabular-nums text-brand-dark"></a>

                    <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs text-red-700"></p>
                </div>
            @endif

            {{-- المراسلة الخاصة: هنا يجوز تبادل الأرقام، وفي السؤال العام لا --}}
            @if ($canMessage)
                <form method="POST" action="{{ route('listing.messages.store', $listing) }}" class="mt-4 border-t border-line pt-4"
                      x-data="messageForm" @submit="submit($event)">
                    @csrf
                    {{-- معرّف الرسالة: يمنع التحديث والضغط المزدوج رسالةً ثانية --}}
                    <input type="hidden" name="client_id" x-ref="clientId" value="{{ old('client_id', \Illuminate\Support\Str::uuid()->toString()) }}">
                    <label for="message-body" class="block text-xs font-semibold">راسل المعلن</label>
                    <textarea id="message-body" name="body" rows="3" maxlength="2000" required
                              placeholder="السلام عليكم، هل الإعلان ما زال متاحاً؟"
                              class="mt-1.5 w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">{{ old('body') }}</textarea>

                    @error('body')
                        <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                    @enderror

                    @error('client_id')
                        <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                    @enderror

                    <button type="submit" :disabled="sending"
                            class="mt-2 w-full rounded-xl border border-line px-4 py-2.5 text-sm font-semibold transition hover:bg-tint disabled:cursor-not-allowed disabled:opacity-60">
                        إرسال رسالة
                    </button>
                </form>
            @elseif (! auth()->check())
                <p class="mt-4 border-t border-line pt-4 text-xs text-muted">
                    <a href="{{ route('login') }}" class="font-medium text-brand-dark hover:underline">سجّل الدخول</a>
                    لمراسلة المعلن.
                </p>
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

{{-- الإبلاغ آخر الصفحة — انظر تعليق القالب المضمَّن --}}
@include('engagement::partials.report', ['listing' => $listing])

@endsection
