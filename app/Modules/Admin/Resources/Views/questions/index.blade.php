@extends('admin::layout')

@section('title', 'الأسئلة العامة')

@section('content')

<h1 class="text-xl font-bold">الأسئلة العامة</h1>
<p class="mt-1 text-sm text-muted">
    الإخفاء للإساءة وحدها. سؤال محرج عن السلعة ليس إساءة — إخفاؤه يحوّل الأسئلة إلى دعاية فيفقدها المشتري.
</p>

<nav class="mt-5 flex gap-2 border-b border-line pb-px" aria-label="تصفية">
    @foreach (['recent' => 'الأحدث', 'unanswered' => 'بلا جواب', 'hidden' => 'مخفية'] as $key => $label)
        <a href="{{ route('admin.questions', ['tab' => $key]) }}"
           @class([
               'rounded-t-lg px-3 py-2 text-sm font-medium transition',
               'bg-white border border-line border-b-white -mb-px text-ink' => $tab === $key,
               'text-muted hover:text-ink' => $tab !== $key,
           ])>{{ $label }}</a>
    @endforeach
</nav>

@if ($questions->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا أسئلة هنا.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($questions as $question)
            <li class="rounded-2xl border border-line bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    @if ($question->listing)
                        <a href="{{ route('listing.show', $question->listing) }}#questions" target="_blank" rel="noopener"
                           class="min-w-0 truncate text-xs font-medium text-brand-dark hover:underline">
                            {{ $question->listing->title }}
                        </a>
                    @else
                        <span class="text-xs text-muted">إعلان محذوف</span>
                    @endif

                    <span class="text-[11px] text-muted">
                        {{ $question->asker?->name ?? 'حساب محذوف' }} · {{ $question->created_at?->diffForHumans() }}
                    </span>
                </div>

                <p class="mt-2 whitespace-pre-line text-sm leading-relaxed">{{ $question->body }}</p>

                @if ($question->isAnswered())
                    <div class="mt-2 rounded-xl bg-tint p-3">
                        <p class="text-[11px] font-semibold text-muted">جواب المعلن</p>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ $question->answer }}</p>
                    </div>
                @else
                    <p class="mt-2 text-xs text-amber-800">لا جواب بعد.</p>
                @endif

                <div class="mt-3 border-t border-line pt-3">
                    @if ($question->hidden_at)
                        <p class="mb-2 text-xs text-muted">
                            مخفي منذ {{ $question->hidden_at->diffForHumans() }}
                            @if ($question->hidden_reason) — {{ $question->hidden_reason }} @endif
                        </p>
                        <form method="POST" action="{{ route('admin.questions.unhide', $question) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-ink px-3 py-2 text-xs font-semibold text-white">
                                إظهار
                            </button>
                        </form>
                    @else
                        @include('admin::partials.reason-form', [
                            'id' => 'hide-' . $question->id,
                            'action' => route('admin.questions.hide', $question),
                            'label' => 'إخفاء',
                            'tone' => 'danger',
                        ])
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $questions->links() }}</div>
@endif

@endsection
