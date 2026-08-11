@extends('shared::layouts.app')

@section('title', 'لوحتي')

@section('content')

<div class="flex flex-wrap items-baseline justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">لوحتي</h1>
        <p class="mt-1 text-sm text-muted">ما يحتاجه إعلانك منك، لا ما يسرّك فقط.</p>
    </div>

    <nav class="flex gap-1 text-xs" aria-label="النافذة الزمنية">
        @foreach ([7 => '7 أيام', 30 => '30 يوماً', 90 => '90 يوماً'] as $option => $label)
            <a href="{{ route('merchant.dashboard', ['days' => $option]) }}"
               @class([
                   'rounded-lg px-2.5 py-1.5 font-medium transition',
                   'bg-ink text-white' => $days === $option,
                   'text-muted hover:text-ink' => $days !== $option,
               ])>{{ $label }}</a>
        @endforeach
    </nav>
</div>

@if ($lastRolledUp === null)
    <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
        لا تجميع للإحصاءات بعد، فالأرقام أدناه أصفار — وليست حقيقة عن إعلاناتك.
    </p>
@endif

{{-- ─── الإشارات أولاً: هي سبب وجود الصفحة ─── --}}
@if ($signals !== [])
    <section class="mt-6">
        <h2 class="text-base font-bold">يحتاج انتباهك</h2>

        <ul class="mt-3 space-y-2">
            @foreach ($signals as $signal)
                <li @class([
                        'rounded-2xl border bg-white p-4',
                        'border-amber-300' => $signal['kind'] === 'urgent',
                        'border-line' => $signal['kind'] !== 'urgent',
                    ])>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-semibold">{{ $signal['title'] }}</p>
                        <a href="{{ route('listing.show', $signal['listing']->slug) }}"
                           class="min-w-0 truncate text-xs font-medium text-brand-dark hover:underline">
                            {{ $signal['listing']->title }}
                        </a>
                    </div>
                    <p class="mt-1.5 text-xs leading-relaxed text-muted">{{ $signal['detail'] }}</p>
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{-- ─── المجاميع ─── --}}
<dl class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-line bg-line sm:grid-cols-3 lg:grid-cols-6">
    @foreach ([
        'إعلاناتي' => $totals['listings'],
        'مشاهدات' => $totals['views'],
        'زوار مختلفون' => $totals['unique_viewers'],
        'ظهور في البحث' => $totals['impressions'],
        'طلبات الرقم' => $totals['contacts'],
        'في مفضلات الناس' => $totals['favorites'],
    ] as $label => $value)
        <div class="bg-white px-4 py-3">
            <dt class="text-xs text-muted">{{ $label }}</dt>
            <dd class="mt-0.5 font-semibold tabular-nums">{{ number_format($value) }}</dd>
        </div>
    @endforeach
</dl>

{{-- ─── الجدول ─── --}}
@if ($rows->isEmpty())
    <p class="mt-6 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا إعلانات بعد.
        <a href="{{ route('listing.create') }}" class="font-medium text-brand-dark hover:underline">أضف أول إعلان</a>.
    </p>
@else
    <div class="mt-6 overflow-x-auto rounded-2xl border border-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-tint text-xs text-muted">
                <tr>
                    <th class="px-3 py-2 text-right font-medium">الإعلان</th>
                    <th class="px-3 py-2 text-right font-medium">مشاهدات</th>
                    <th class="px-3 py-2 text-right font-medium">ظهور</th>
                    <th class="px-3 py-2 text-right font-medium">نقر</th>
                    <th class="px-3 py-2 text-right font-medium">نسبة النقر</th>
                    <th class="px-3 py-2 text-right font-medium">طلب رقم</th>
                    <th class="px-3 py-2 text-right font-medium">مفضلة</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($rows as $row)
                    <tr>
                        <td class="max-w-[16rem] px-3 py-2">
                            <a href="{{ route('listing.show', $row->slug) }}" class="block truncate hover:underline">
                                {{ $row->title }}
                            </a>
                            @if ($row->status !== 'active')
                                <span class="text-[11px] text-muted">({{ $row->status }})</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($row->views) }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($row->impressions) }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($row->clicks) }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{-- الشرطة تعني «ظهور غير كافٍ للحكم» لا صفراً --}}
                            {{ $row->ctr === null ? '—' : $row->ctr . '%' }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($row->contacts) }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($row->favorites_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-muted">
        «ظهور» = مرات ظهور الإعلان في نتائج بحث. «نسبة النقر» تُحسب عند عشرين ظهوراً على الأقل —
        ظهور واحد ونقرة واحدة يعطيان 100% وهو رقم لا يعني شيئاً.
    </p>
@endif

<p class="mt-6 text-sm">
    <a href="{{ route('merchant.interactions') }}" class="font-medium text-brand-dark hover:underline">
        تفاعل الناس مع إعلاناتي →
    </a>
</p>

@endsection
