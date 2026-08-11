@extends('shared::layouts.app')

@section('title', 'إعلاناتي')

@php
    $tabs = [
        'live' => 'المنشورة',
        'sold' => 'المباعة',
        'expired' => 'المنتهية',
        'trashed' => 'المحذوفة',
        'all' => 'الكل',
    ];
@endphp

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-bold">إعلاناتي</h1>
    <a href="{{ route('listing.create') }}" class="text-sm font-medium text-brand-dark hover:underline">
        + إعلان جديد
    </a>
</div>

{{-- التبويبات تحمل عدّادها: «المنتهية (3)» يدفع للضغط، و«المنتهية» وحدها لا --}}
<nav class="mt-5 flex flex-wrap gap-2 border-b border-line pb-px" aria-label="تصفية الإعلانات">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('account.listings', ['tab' => $key]) }}"
           @class([
               'rounded-t-lg px-3.5 py-2 text-sm font-medium transition',
               'bg-white border border-line border-b-white -mb-px text-ink' => $tab === $key,
               'text-muted hover:text-ink' => $tab !== $key,
           ])
           @if ($tab === $key) aria-current="page" @endif>
            {{ $label }}
            <span class="tabular-nums text-xs text-muted">({{ $counts[$key] }})</span>
        </a>
    @endforeach
</nav>

@if ($listings->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا إعلانات في هذا التبويب.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($listings as $listing)
            @php
                $days = $listing->daysUntilExpiry();
                $canRenew = $renewal->canRenew($listing) && ! $listing->trashed();
            @endphp

            <li class="rounded-2xl border border-line bg-white p-3.5">
                <div class="flex gap-3.5">
                    <div class="size-20 shrink-0 overflow-hidden rounded-xl bg-tint sm:size-24">
                        @if ($listing->images->isNotEmpty())
                            <img src="{{ asset('storage/' . ($listing->images->first()->thumb_path ?? $listing->images->first()->path)) }}"
                                 alt="" loading="lazy" class="size-full object-cover">
                        @else
                            <div class="grid size-full place-items-center text-[10px] text-muted">لا صورة</div>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <h2 class="min-w-0 text-sm font-semibold leading-snug">
                                @if ($listing->trashed())
                                    <span class="text-muted">{{ $listing->title }}</span>
                                @else
                                    <a href="{{ route('listing.show', $listing) }}" class="hover:underline">
                                        {{ $listing->title }}
                                    </a>
                                @endif
                            </h2>

                            <span @class([
                                'shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold',
                                'bg-green-100 text-green-900' => ! $listing->trashed() && ! $listing->isExpired() && $listing->status === 'active',
                                'bg-amber-100 text-amber-900' => ! $listing->trashed() && $listing->isExpired(),
                                'bg-tint text-muted' => $listing->trashed() || (! $listing->isExpired() && $listing->status !== 'active'),
                            ])>
                                {{ $listing->ownerStatusLabel() }}
                            </span>
                        </div>

                        <p class="mt-1 text-sm font-bold tabular-nums text-brand-dark">
                            {{ $listing->formattedPrice() }}
                        </p>

                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                            <span class="tabular-nums">{{ number_format($listing->views_count) }} مشاهدة</span>
                            <span aria-hidden="true">·</span>
                            <span class="tabular-nums">{{ number_format($listing->contacts_count) }} تواصل</span>

                            @if ($listing->trashed())
                                <span aria-hidden="true">·</span>
                                <span>حُذف {{ $listing->deleted_at?->diffForHumans() }}</span>
                            @elseif ($days === null)
                                <span aria-hidden="true">·</span>
                                <span>بلا تاريخ انتهاء</span>
                            @elseif ($days < 0)
                                <span aria-hidden="true">·</span>
                                <span class="text-amber-800">انتهى قبل {{ abs($days) }} يوم</span>
                            @else
                                <span aria-hidden="true">·</span>
                                <span @class(['text-amber-800' => $days <= 7])>يتبقى {{ $days }} يوم</span>
                            @endif

                            @if ($listing->renewals_count > 0)
                                <span aria-hidden="true">·</span>
                                <span>جُدِّد {{ $listing->renewals_count }} مرة</span>
                            @endif
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @if ($listing->trashed())
                                <form method="POST" action="{{ route('account.listings.restore', $listing) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                        استعادة
                                    </button>
                                </form>
                            @else
                                <a href="{{ route('account.listings.edit', $listing) }}"
                                   class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                    تعديل
                                </a>

                                <a href="{{ route('account.listings.stats', $listing) }}"
                                   class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                    المشاهدات
                                </a>

                                {{--
                                    زرّ البيع يبدّل نصّه بحسب الحالة، والمسار واحد:
                                    مساران يعنيان أن الواجهة تقرّر أيّهما تستدعي،
                                    فتُرسل «بِع» على إعلان مباع إن تأخّر تحديث الصفحة.
                                --}}
                                @if ($listing->status === 'sold')
                                    <form method="POST" action="{{ route('account.listings.sold', $listing) }}">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                            إرجاع إلى العرض
                                        </button>
                                    </form>
                                @elseif (in_array($listing->status, ['active', 'paused'], true))
                                    <form method="POST" action="{{ route('account.listings.sold', $listing) }}"
                                          onsubmit="return confirm('تعليم «{{ addslashes($listing->title) }}» مباعاً؟ سيختفي من نتائج البحث، ويمكنك إرجاعه من تبويب المباعة.')">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                            تم البيع
                                        </button>
                                    </form>
                                @endif

                                {{-- الزر المُعطَّل يبقى مرئياً ويقول سبب تعطيله:
                                     زر يظهر ويختفي يجعل المعلن يظن أن الميزة غير موجودة --}}
                                @if ($canRenew)
                                    <form method="POST" action="{{ route('account.listings.renew', $listing) }}">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark">
                                            تجديد 30 يوماً
                                        </button>
                                    </form>
                                @else
                                    <span class="cursor-not-allowed rounded-lg border border-line px-3 py-1.5 text-xs text-muted"
                                          title="التجديد متاح في آخر 7 أيام قبل الانتهاء">
                                        تجديد غير متاح بعد
                                    </span>
                                @endif

                                <form method="POST" action="{{ route('account.listings.destroy', $listing) }}"
                                      onsubmit="return confirm('حذف «{{ addslashes($listing->title) }}»؟ يمكنك استعادته من تبويب المحذوفة.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-50">
                                        حذف
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">
        {{ $listings->links() }}
    </div>
@endif

@endsection