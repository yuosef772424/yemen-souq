@extends('shared::layouts.app')

@section('title', 'أسئلة على إعلاناتي')

@section('content')

<h1 class="text-xl font-bold">أسئلة على إعلاناتي</h1>
<p class="mt-1 text-sm text-muted">غير المُجاب أولاً — السؤال المعلّق يقرأه كل زائر كإشارة إلى بائع لا يردّ.</p>

@if ($questions->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا أسئلة على إعلاناتك.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($questions as $question)
            <li class="rounded-2xl border border-line bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <a href="{{ route('listing.show', $question->listing) }}#questions"
                       class="text-xs font-medium text-brand-dark hover:underline">
                        {{ $question->listing?->title }}
                    </a>
                    <p class="text-[11px] text-muted">{{ $question->created_at?->diffForHumans() }}</p>
                </div>

                <p class="mt-2 whitespace-pre-line text-sm leading-relaxed">{{ $question->body }}</p>

                @if ($question->isAnswered())
                    <div class="mt-3 rounded-xl bg-tint p-3">
                        <p class="text-[11px] font-semibold text-muted">جوابك</p>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ $question->answer }}</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('questions.answer', $question) }}" class="mt-3">
                        @csrf
                        <label class="sr-only" for="inbox-answer-{{ $question->id }}">جوابك</label>
                        <textarea id="inbox-answer-{{ $question->id }}" name="answer" rows="2" maxlength="800" required
                                  placeholder="اكتب جوابك — سيظهر لكل الزوار"
                                  class="w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none"></textarea>
                        <button type="submit"
                                class="mt-2 rounded-lg bg-ink px-4 py-2 text-xs font-semibold text-white transition hover:bg-black">
                            نشر الجواب
                        </button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $questions->links() }}</div>
@endif

@endsection