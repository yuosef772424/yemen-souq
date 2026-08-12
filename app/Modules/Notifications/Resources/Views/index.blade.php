@extends('shared::layouts.app')

@section('title', 'الإشعارات')

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-bold">
        الإشعارات
        @if ($unread > 0)
            <span class="ms-1 align-middle text-sm font-semibold text-brand-dark">{{ $unread }} جديد</span>
        @endif
    </h1>

    @if ($unread > 0)
        <form method="POST" action="{{ route('account.notifications.read-all') }}">
            @csrf
            <button type="submit"
                    class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                وسم الكل مقروءاً
            </button>
        </form>
    @endif
</div>

@if ($notifications->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا إشعارات بعد. سيصلك هنا خبرُ كل رسالة وسؤال وقرارٍ يخصّ إعلاناتك.
    </p>
@else
    {{--
        كل صفّ نموذج POST لا رابطاً: الفتح يوسم مقروءاً، والوسم تغييرُ
        حالة — انظر NotificationController::open.

        والزرّ بعرض الصفّ كاملاً (text-start وw-full) ليقرأه المستخدم
        كسطر قابل للنقر لا كزرّ صغير في طرفه.
    --}}
    <ul class="mt-5 space-y-2">
        @foreach ($notifications as $notification)
            <li @class([
                'rounded-2xl border bg-white transition',
                'border-brand/40 bg-brand/[0.03]' => ! $notification->isRead(),
                'border-line' => $notification->isRead(),
            ])>
                <form method="POST" action="{{ route('account.notifications.open', $notification) }}">
                    @csrf
                    <button type="submit" class="flex w-full items-start gap-3 p-4 text-start">
                        {{-- النقطة وحدها تحمل حالة القراءة، ومعها وسم نصّي
                             لقارئ الشاشة: اللون لا يصل من لا يراه --}}
                        <span class="mt-1.5 shrink-0">
                            @if ($notification->isRead())
                                <span class="block size-2 rounded-full bg-line"></span>
                            @else
                                <span class="block size-2 rounded-full bg-brand"></span>
                                <span class="sr-only">غير مقروء</span>
                            @endif
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold leading-snug">{{ $notification->title }}</span>

                            @if ($notification->body)
                                <span class="mt-1 block text-sm leading-relaxed text-muted">{{ $notification->body }}</span>
                            @endif

                            <span class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-muted">
                                <span>{{ $notification->type->label() }}</span>
                                <span aria-hidden="true">·</span>
                                <time datetime="{{ $notification->created_at?->toIso8601String() }}">
                                    {{ $notification->created_at?->diffForHumans() }}
                                </time>
                            </span>
                        </span>
                    </button>
                </form>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $notifications->links() }}</div>
@endif

@endsection
