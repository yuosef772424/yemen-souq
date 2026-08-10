@extends('shared::layouts.app')

@section('title', 'المفضلة')

@section('content')

<h1 class="text-xl font-bold">المفضلة</h1>

@if ($listings->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا إعلانات محفوظة. استخدم زرّ «أضف إلى المفضلة» في صفحة أي إعلان.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($listings as $listing)
            <li class="rounded-2xl border border-line bg-white p-3.5">
                <div class="flex gap-3.5">
                    <div class="size-20 shrink-0 overflow-hidden rounded-xl bg-tint">
                        @if ($listing->images->isNotEmpty())
                            <img src="{{ asset('storage/' . ($listing->images->first()->thumb_path ?? $listing->images->first()->path)) }}"
                                 alt="" loading="lazy" class="size-full object-cover">
                        @else
                            <div class="grid size-full place-items-center text-[10px] text-muted">لا صورة</div>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        {{-- المحذوف والمنتهي يبقيان في القائمة بوسم صريح:
                             إخفاؤهما يجعل المستخدم يظن أنه فقد ما حفظه --}}
                        @php
                            $gone = $listing->trashed() || $listing->status !== 'active';
                            $expired = ! $gone && $listing->isExpired();
                        @endphp

                        <h2 class="text-sm font-semibold leading-snug">
                            @if ($listing->trashed())
                                <span class="text-muted">{{ $listing->title }}</span>
                            @else
                                <a href="{{ route('listing.show', $listing) }}" class="hover:underline">{{ $listing->title }}</a>
                            @endif
                        </h2>

                        <p class="mt-1 text-sm font-bold tabular-nums text-brand-dark">{{ $listing->formattedPrice() }}</p>

                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                            <span>{{ $listing->location?->name_ar }}</span>

                            @if ($listing->trashed())
                                <span class="rounded-md bg-tint px-2 py-0.5 font-semibold">حُذف الإعلان</span>
                            @elseif ($gone)
                                <span class="rounded-md bg-tint px-2 py-0.5 font-semibold">غير متاح</span>
                            @elseif ($expired)
                                <span class="rounded-md bg-amber-100 px-2 py-0.5 font-semibold text-amber-900">منتهي</span>
                            @endif
                        </p>

                        <form method="POST" action="{{ route('listing.favorite', $listing) }}" class="mt-3">
                            @csrf
                            <button type="submit"
                                    class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                إزالة من المفضلة
                            </button>
                        </form>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $listings->links() }}</div>
@endif

@endsection