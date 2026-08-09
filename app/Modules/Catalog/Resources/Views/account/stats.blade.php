@extends('shared::layouts.app')

@section('title', 'مشاهدات: ' . $listing->title)

@php
    $totals = $stats['totals'];
    $ctr = $reader->clickRate($totals['search_impressions'], $totals['search_clicks']);
@endphp

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        <h1 class="text-xl font-bold">المشاهدات</h1>
        <p class="mt-1 truncate text-sm text-muted">{{ $listing->title }}</p>
    </div>
    <div class="flex gap-3 text-sm">
        <a href="{{ route('account.listings.edit', $listing) }}" class="text-brand-dark hover:underline">تعديل</a>
        <a href="{{ route('account.listings') }}" class="text-muted hover:underline">إعلاناتي</a>
    </div>
</div>

{{--
    العدّاد الفوري أولاً، والتفصيل اليومي بعده.

    الرقمان يأتيان من مصدرين مختلفين وقد لا يتساويان: العدّاد يُزاد لحظة
    الزيارة، والتفصيل يكتبه analytics:rollup لاحقاً. إخفاء هذا الفرق
    يجعل المعلن يظن أحد الرقمين خطأ؛ لذا كل رقم يقول من أين جاء.
--}}
<dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
    @foreach ([
        ['إجمالي المشاهدات', number_format($listing->views_count), 'عدّاد فوري منذ النشر'],
        ['مرات التواصل', number_format($listing->contacts_count), 'كشف الرقم'],
        ['مشاهدات آخر ' . count($stats['rows']) . ' يوم', number_format($totals['views']), 'من التجميع اليومي'],
        ['زوّار مختلفون', number_format($totals['unique_viewers']), 'في نفس المدة'],
    ] as [$label, $value, $note])
        <div class="rounded-2xl border border-line bg-white p-4">
            <dt class="text-xs text-muted">{{ $label }}</dt>
            <dd class="mt-1 text-2xl font-bold tabular-nums">{{ $value }}</dd>
            <p class="mt-1 text-[11px] text-muted">{{ $note }}</p>
        </div>
    @endforeach
</dl>

@if ($stats['lastRolledUp'] === null)
    {{-- جدول أصفار بلا تفسير يقول للمعلن «لم يرَك أحد». الصحيح أن يقول
         «لم يُحسب بعد» — وهذان معنيان مختلفان تماماً --}}
    <p role="status" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        التفصيل اليومي لم يُحسب بعد. الأرقام أعلاه من العدّاد الفوري وهي صحيحة،
        أما الجدول أدناه فيبقى فارغاً حتى تشغيل <code class="rounded bg-white px-1">php artisan analytics:rollup</code>.
    </p>
@else
    <p class="mt-5 text-xs text-muted">آخر تجميع يومي: {{ $stats['lastRolledUp'] }}</p>
@endif

{{-- ═══ الظهور في البحث ═══ --}}
<div class="mt-5 rounded-2xl border border-line bg-white p-5">
    <h2 class="text-sm font-semibold">الظهور في نتائج البحث</h2>

    <div class="mt-3 flex flex-wrap gap-6 text-sm">
        <p>ظهر <span class="font-bold tabular-nums">{{ number_format($totals['search_impressions']) }}</span> مرة</p>
        <p>نُقر <span class="font-bold tabular-nums">{{ number_format($totals['search_clicks']) }}</span> مرة</p>
        <p>
            نسبة النقر:
            @if ($ctr === null)
                <span class="text-muted">لا تكفي البيانات بعد</span>
            @else
                <span class="font-bold tabular-nums">{{ $ctr }}%</span>
            @endif
        </p>
    </div>

    <p class="mt-3 text-xs text-muted">
        ظهور كثير مع نقر قليل يعني عادةً أن الصورة الأولى أو العنوان لا يُقنعان في النتائج، لا أن السعر مرتفع.
    </p>
</div>

{{-- ═══ التفصيل اليومي ═══ --}}
<div class="mt-5 overflow-hidden rounded-2xl border border-line bg-white">
    <table class="w-full text-sm">
        <caption class="border-b border-line px-4 py-3 text-start text-sm font-semibold">
            آخر {{ count($stats['rows']) }} يوم
        </caption>
        <thead class="bg-tint text-xs text-muted">
            <tr>
                <th scope="col" class="px-4 py-2 text-start font-medium">اليوم</th>
                <th scope="col" class="px-4 py-2 text-start font-medium">مشاهدات</th>
                <th scope="col" class="hidden px-4 py-2 text-start font-medium sm:table-cell">زوّار</th>
                <th scope="col" class="hidden px-4 py-2 text-start font-medium sm:table-cell">ظهور</th>
                <th scope="col" class="hidden px-4 py-2 text-start font-medium sm:table-cell">نقر</th>
                <th scope="col" class="px-4 py-2 text-start font-medium">تواصل</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_reverse($stats['rows']) as $row)
                <tr class="border-t border-line">
                    <td class="px-4 py-2 tabular-nums">{{ $row['date'] }}</td>
                    <td class="px-4 py-2">
                        <div class="flex items-center gap-2">
                            <span class="w-8 tabular-nums">{{ $row['views'] }}</span>
                            {{-- العرض المئوي قيمة محسوبة لكل صف، وTailwind لا يولّد
                                 أصنافاً ديناميكية — فالنمط المضمّن هو الحل الصحيح هنا --}}
                            <span class="h-1.5 rounded-full bg-brand"
                                  style="width: {{ round($row['views'] / $stats['peak'] * 100) }}%"></span>
                        </div>
                    </td>
                    <td class="hidden px-4 py-2 tabular-nums sm:table-cell">{{ $row['unique_viewers'] }}</td>
                    <td class="hidden px-4 py-2 tabular-nums sm:table-cell">{{ $row['search_impressions'] }}</td>
                    <td class="hidden px-4 py-2 tabular-nums sm:table-cell">{{ $row['search_clicks'] }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ $row['contacts'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<p class="mt-4 text-xs text-muted">
    زياراتك لإعلانك لا تُحسب في أي من هذه الأرقام.
</p>

@endsection