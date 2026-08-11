@extends('admin::layout')

@section('title', 'مراجعة الإعلانات')

@php
    $tabs = [
        'queue' => 'لم تُراجَع',
        'reported' => 'مُبلَّغ عنها',
        'rejected' => 'مرفوضة',
        'featured' => 'مميّزة',
        'trashed' => 'محذوفة',
        'all' => 'الكل',
    ];
@endphp

@section('content')

<div class="flex flex-wrap items-baseline justify-between gap-3">
    <h1 class="text-xl font-bold">مراجعة الإعلانات</h1>

    <form method="GET" action="{{ route('admin.listings') }}" class="flex items-center gap-2">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label class="sr-only" for="q">بحث</label>
        <input type="search" id="q" name="q" value="{{ $q }}" placeholder="عنوان أو رقم إعلان"
               class="rounded-lg border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">
        <button type="submit" class="rounded-lg bg-ink px-3 py-2 text-xs font-semibold text-white">بحث</button>
    </form>
</div>

<nav class="mt-5 flex flex-wrap gap-2 border-b border-line pb-px" aria-label="تصفية">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('admin.listings', ['tab' => $key]) }}"
           @class([
               'rounded-t-lg px-3 py-2 text-sm font-medium transition',
               'bg-white border border-line border-b-white -mb-px text-ink' => $tab === $key,
               'text-muted hover:text-ink' => $tab !== $key,
           ])>
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
            <li class="rounded-2xl border border-line bg-white p-4">

                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="min-w-0 text-sm font-semibold">
                        <a href="{{ route('listing.show', $listing) }}" target="_blank" rel="noopener"
                           class="hover:underline">{{ $listing->title }}</a>
                        <span class="text-xs font-normal text-muted">#{{ $listing->id }}</span>
                    </h2>

                    <div class="flex shrink-0 items-center gap-1.5 text-[11px]">
                        @if ($listing->reviewed_at === null)
                            <span class="rounded-md bg-amber-100 px-2 py-0.5 font-semibold text-amber-900">لم تُراجَع</span>
                        @endif
                        @if ($listing->isFeaturedNow())
                            <span class="rounded-md bg-brand/10 px-2 py-0.5 font-semibold text-brand-dark">مميّز</span>
                        @endif
                        <span class="rounded-md bg-tint px-2 py-0.5 font-semibold text-muted">
                            {{ $listing->ownerStatusLabel() }}
                        </span>
                    </div>
                </div>

                <p class="mt-1.5 text-xs text-muted">
                    {{ $listing->user?->name ?? 'حساب محذوف' }}
                    @if ($listing->user?->phone) · {{ $listing->user->phone }} @endif
                    · {{ $listing->category?->name }}
                    · {{ $listing->location?->name }}
                    · {{ $listing->formattedPrice() }}
                    · {{ $listing->created_at?->diffForHumans() }}
                    @isset($listing->open_reports_count)
                        · <span class="font-semibold text-red-700">{{ $listing->open_reports_count }} بلاغ مفتوح</span>
                    @endisset
                </p>

                @if ($listing->reject_reason)
                    <p class="mt-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900">
                        سبب الرفض: {{ $listing->reject_reason }}
                    </p>
                @endif

                {{-- الأفعال: الرفض والحذف بسبب مكتوب، والبقية بضغطة --}}
                <div class="mt-3 space-y-2 border-t border-line pt-3">

                    @if ($listing->trashed())
                        <form method="POST" action="{{ route('admin.listings.restore', $listing) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-ink px-3 py-2 text-xs font-semibold text-white">
                                استعادة
                            </button>
                        </form>
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($listing->reviewed_at === null || $listing->status === 'rejected')
                                <form method="POST" action="{{ route('admin.listings.approve', $listing) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg bg-green-700 px-3 py-2 text-xs font-semibold text-white transition hover:bg-green-800">
                                        اعتماد
                                    </button>
                                </form>
                            @endif

                            @if ($listing->isFeaturedNow())
                                <form method="POST" action="{{ route('admin.listings.unfeature', $listing) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-muted transition hover:text-ink">
                                        إلغاء التمييز
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.listings.feature', $listing) }}" class="flex items-center gap-1.5">
                                    @csrf
                                    <label class="sr-only" for="days-{{ $listing->id }}">أيام</label>
                                    <input type="number" id="days-{{ $listing->id }}" name="days" value="7" min="1" max="90"
                                           class="w-16 rounded-lg border border-line px-2 py-2 text-xs focus:border-brand focus:outline-none">
                                    <button type="submit" class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-muted transition hover:text-ink">
                                        تمييز
                                    </button>
                                </form>
                            @endif

                            <form method="POST" action="{{ route('admin.listings.pause', $listing) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-muted transition hover:text-ink">
                                    إيقاف
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.listings.destroy', $listing) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-50">
                                    حذف
                                </button>
                            </form>
                        </div>

                        @if ($listing->status !== 'rejected')
                            @include('admin::partials.reason-form', [
                                'id' => 'reject-' . $listing->id,
                                'action' => route('admin.listings.reject', $listing),
                                'label' => 'رفض',
                                'tone' => 'danger',
                            ])
                        @endif
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $listings->links() }}</div>
@endif

@endsection
