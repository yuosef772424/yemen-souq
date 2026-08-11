@extends('admin::layout')

@section('title', 'لوحة القيادة')

@section('content')

{{--
    الترتيب في هذه الصفحة قرار لا تصميم: طابور العمل أولاً، ثم صحة
    النظام، ثم الأرقام الوصفية. لأن المشرف يفتح اللوحة ليعمل لا ليقرأ —
    ورقم كبير في الأعلى بلا فعل مطلوب منه يعلّمه أن اللوحة للتزيين.
--}}

<h1 class="text-xl font-bold">لوحة القيادة</h1>

@php
    $queueCards = [
        ['label' => 'إعلانات لم تُراجَع', 'value' => $queue['unreviewed'], 'href' => route('admin.listings', ['tab' => 'queue'])],
        ['label' => 'بلاغات مفتوحة', 'value' => $queue['reports'], 'href' => route('admin.reports')],
        ['label' => 'أسئلة بلا جواب', 'value' => $queue['unanswered'], 'href' => route('admin.questions', ['tab' => 'unanswered'])],
        ['label' => 'إعلانات مرفوضة', 'value' => $queue['rejected'], 'href' => route('admin.listings', ['tab' => 'rejected'])],
    ];
@endphp

<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ($queueCards as $card)
        <a href="{{ $card['href'] }}"
           @class([
               'rounded-2xl border bg-white p-4 transition hover:border-ink',
               'border-amber-300' => $card['value'] > 0,
               'border-line' => $card['value'] === 0,
           ])>
            <p class="text-xs font-medium text-muted">{{ $card['label'] }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($card['value']) }}</p>
        </a>
    @endforeach
</div>

{{-- ─── صحة النظام ─── --}}
<section class="mt-8">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-base font-bold">صحة النظام</h2>
        <p class="text-xs text-muted">{{ $checksTotal }} فحصاً · يُعرض المختل وحده</p>
    </div>

    @if ($issues === [])
        <p class="mt-3 rounded-2xl border border-green-200 bg-green-50 px-4 py-4 text-sm text-green-900">
            كل الفحوص سليمة.
        </p>
    @else
        <ul class="mt-3 space-y-2">
            @foreach ($issues as $issue)
                <li @class([
                        'rounded-xl border px-4 py-3 text-sm',
                        'border-red-200 bg-red-50 text-red-900' => $issue['status'] === 'fail',
                        'border-amber-200 bg-amber-50 text-amber-900' => $issue['status'] !== 'fail',
                    ])>
                    <span class="font-semibold">{{ $issue['name'] }}</span>
                    <span class="text-xs">— {{ $issue['detail'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>

{{-- ─── القمع ─── --}}
<section class="mt-8">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-base font-bold">آخر سبعة أيام</h2>
        <p class="text-xs text-muted">
            @if ($lastRolledUp === null)
                لا تجميع بعد — شغّل <code class="rounded bg-tint px-1">php artisan analytics:rollup</code> وإلا بقيت الأرقام أصفاراً
            @else
                آخر تجميع: {{ $lastRolledUp }}
            @endif
        </p>
    </div>

    <div class="mt-3 overflow-x-auto rounded-2xl border border-line bg-white">
        <table class="w-full text-sm">
            <thead class="bg-tint text-xs text-muted">
                <tr>
                    <th class="px-3 py-2 text-right font-medium">اليوم</th>
                    <th class="px-3 py-2 text-right font-medium">بحث</th>
                    <th class="px-3 py-2 text-right font-medium">بحث بنقرة</th>
                    <th class="px-3 py-2 text-right font-medium">مشاهدات</th>
                    <th class="px-3 py-2 text-right font-medium">كشف رقم</th>
                    <th class="px-3 py-2 text-right font-medium">إعلانات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($funnel as $day)
                    <tr>
                        <td class="px-3 py-2 text-xs text-muted">{{ $day['date'] }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($day['searches']) }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($day['searches_with_click']) }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($day['listing_views']) }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($day['contacts_revealed']) }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ number_format($day['listings_created']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<div class="mt-8 grid gap-6 lg:grid-cols-2">

    {{-- ─── بحث بلا نتيجة ─── --}}
    <section>
        <h2 class="text-base font-bold">بحث بلا نتيجة</h2>
        <p class="mt-1 text-xs text-muted">كل سطر طلبٌ في السوق بلا عرض عندنا — نقص مخزون أو عيب بحث.</p>

        @if ($zeroQueries === [])
            <p class="mt-3 rounded-2xl border border-line bg-white px-4 py-6 text-center text-sm text-muted">
                لا بيانات — يحتاج تجميعاً وحركة بحث حقيقية.
            </p>
        @else
            <ul class="mt-3 divide-y divide-line rounded-2xl border border-line bg-white">
                @foreach ($zeroQueries as $row)
                    <li class="flex items-baseline justify-between gap-3 px-4 py-2.5 text-sm">
                        <span class="min-w-0 truncate">{{ $row['query'] }}</span>
                        <span class="shrink-0 text-xs tabular-nums text-muted">
                            {{ number_format($row['zeros']) }} بلا نتيجة من {{ number_format($row['searches']) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ─── حال المنصة ─── --}}
    <section>
        <h2 class="text-base font-bold">حال المنصة</h2>
        <p class="mt-1 text-xs text-muted">وصف لا طابور — لا يُصفَّر بعمل.</p>

        <dl class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-line bg-line text-sm">
            @foreach ([
                'إعلانات' => number_format($overview['listings_total']),
                'إعلانات اليوم' => number_format($overview['listings_today']),
                'مميّزة سارية' => number_format($overview['listings_featured']),
                'محذوفة' => number_format($overview['listings_trashed']),
                'حسابات' => number_format($overview['users_total']),
                'حسابات اليوم' => number_format($overview['users_today']),
                'موقوفة أو محظورة' => number_format($overview['users_blocked']),
                'منشورة' => number_format($overview['listings_by_status']['active'] ?? 0),
            ] as $label => $value)
                <div class="bg-white px-4 py-3">
                    <dt class="text-xs text-muted">{{ $label }}</dt>
                    <dd class="mt-0.5 font-semibold tabular-nums">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</div>

{{-- ─── سجل المساءلة ─── --}}
<section class="mt-8">
    <h2 class="text-base font-bold">آخر الإجراءات</h2>

    @if ($recent->isEmpty())
        <p class="mt-3 rounded-2xl border border-line bg-white px-4 py-6 text-center text-sm text-muted">
            لا إجراءات بعد.
        </p>
    @else
        <ul class="mt-3 divide-y divide-line rounded-2xl border border-line bg-white">
            @foreach ($recent as $entry)
                <li class="px-4 py-2.5 text-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-medium">{{ $entry->label() }}</span>
                        <span class="text-xs text-muted">
                            {{ $entry->admin?->name ?? 'من الطرفية' }} · {{ $entry->created_at?->diffForHumans() }}
                        </span>
                    </div>
                    @if ($entry->reason)
                        <p class="mt-1 text-xs text-muted">{{ $entry->reason }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>

@endsection
