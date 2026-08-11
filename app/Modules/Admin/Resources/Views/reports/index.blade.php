@extends('admin::layout')

@section('title', 'البلاغات')

@section('content')

<h1 class="text-xl font-bold">البلاغات</h1>
<p class="mt-1 text-sm text-muted">الأقدم أولاً — بلاغ ينتظر أسبوعاً يعني إعلاناً سيئاً بقي أسبوعاً.</p>

<nav class="mt-5 flex gap-2 border-b border-line pb-px" aria-label="تصفية">
    @foreach (['open' => 'مفتوحة', 'closed' => 'مغلقة'] as $key => $label)
        <a href="{{ route('admin.reports', ['tab' => $key]) }}"
           @class([
               'rounded-t-lg px-3 py-2 text-sm font-medium transition',
               'bg-white border border-line border-b-white -mb-px text-ink' => $tab === $key,
               'text-muted hover:text-ink' => $tab !== $key,
           ])>
            {{ $label }}
            <span class="tabular-nums text-xs text-muted">({{ $key === 'open' ? $openCount : $closedCount }})</span>
        </a>
    @endforeach
</nav>

@if ($reports->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا بلاغات هنا.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($reports as $report)
            <li class="rounded-2xl border border-line bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="rounded-md bg-tint px-2 py-0.5 text-[11px] font-semibold">{{ $report->reasonLabel() }}</span>
                    <span class="text-xs text-muted">
                        {{ $report->statusLabel() }} · {{ $report->created_at?->diffForHumans() }}
                    </span>
                </div>

                <p class="mt-2 text-sm font-semibold">
                    @if ($report->listing)
                        <a href="{{ route('listing.show', $report->listing) }}" target="_blank" rel="noopener" class="hover:underline">
                            {{ $report->listing->title }}
                        </a>
                        <span class="text-xs font-normal text-muted">#{{ $report->listing->id }}</span>
                        @if ($report->listing->trashed())
                            <span class="rounded-md bg-tint px-2 py-0.5 text-[11px] text-muted">محذوف</span>
                        @endif
                    @else
                        <span class="text-muted">الإعلان غير موجود</span>
                    @endif
                </p>

                @if ($report->details)
                    <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed">{{ $report->details }}</p>
                @endif

                <p class="mt-1.5 text-xs text-muted">
                    المُبلِّغ: {{ $report->reporter?->name ?? 'زائر' }}
                    @if ($report->reviewer) · فصل فيه: {{ $report->reviewer->name }} @endif
                </p>

                @if ($report->isOpen())
                    <div class="mt-3 space-y-2 border-t border-line pt-3">
                        @include('admin::partials.reason-form', [
                            'id' => 'report-' . $report->id,
                            'action' => route('admin.reports.action', $report),
                            'label' => 'تنفيذ ورفض الإعلان',
                            'tone' => 'danger',
                        ])

                        <form method="POST" action="{{ route('admin.reports.dismiss', $report) }}">
                            @csrf
                            <button type="submit" class="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-muted transition hover:text-ink">
                                صرف البلاغ
                            </button>
                        </form>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $reports->links() }}</div>
@endif

@endsection
