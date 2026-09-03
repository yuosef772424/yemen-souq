@extends('shared::layouts.app')

@section('title', 'رسائلي')

@section('content')

<h1 class="text-xl font-bold">رسائلي</h1>

@if ($conversations->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا محادثات بعد.
    </p>
@else
    {{-- قائمة واحدة تجمع ما بعتَ وما اشتريت.
         قائمتان منفصلتان («كمشترٍ» و«كبائع») تبدو أنظف وتفشل عملياً:
         المستخدم يتذكّر أنه كلّم فلاناً عن سيارة، لا أي دور لعب --}}
    <ul class="mt-5 divide-y divide-line overflow-hidden rounded-2xl border border-line bg-white">
        @foreach ($conversations as $conversation)
            @php
                $counterpart = $conversation->counterpart($user);
                $unread = $conversation->unreadCountFor($user);
                $role = $user->id === $conversation->buyer_id ? 'أنت المشتري' : 'أنت البائع';
            @endphp

            <li>
                <a href="{{ route('account.messages.show', $conversation) }}"
                   class="flex gap-3.5 p-4 transition hover:bg-tint">
                    <div class="size-14 shrink-0 overflow-hidden rounded-xl bg-tint">
                        @if ($conversation->listing?->images->isNotEmpty())
                            <img src="{{ $conversation->listing->images->first()->thumbUrl() }}"
                                 alt="" loading="lazy" class="size-full object-cover">
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-semibold">{{ $counterpart?->displayName() ?? 'مستخدم' }}</p>
                            <p class="text-[11px] text-muted">{{ $conversation->last_message_at?->diffForHumans() }}</p>
                        </div>

                        <p class="mt-0.5 truncate text-xs text-muted">{{ $conversation->listing?->title }}</p>

                        <p class="mt-1 flex items-center gap-2 text-[11px]">
                            <span class="text-muted">{{ $role }}</span>

                            @if ($unread > 0)
                                <span class="rounded-md bg-brand px-2 py-0.5 font-semibold tabular-nums text-white">
                                    {{ $unread }} جديدة
                                </span>
                            @endif
                        </p>
                    </div>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $conversations->links() }}</div>
@endif

@endsection