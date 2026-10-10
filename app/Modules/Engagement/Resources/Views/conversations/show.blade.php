@extends('shared::layouts.app')

@section('title', 'محادثة — ' . ($conversation->listing?->title ?? ''))

@section('content')

<div class="mx-auto max-w-2xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-bold">{{ $counterpart?->displayName() ?? 'مستخدم' }}</h1>
            <p class="mt-0.5 truncate text-xs text-muted">
                @if ($conversation->listing)
                    <a href="{{ route('listing.show', $conversation->listing) }}" class="hover:underline">
                        {{ $conversation->listing->title }}
                    </a>
                @endif
            </p>
        </div>
        <a href="{{ route('account.messages') }}" class="text-sm text-muted hover:underline">كل الرسائل</a>
    </div>

    {{-- الإعلان المحذوف لا يقطع الخيط: قد يكون الطرفان في منتصف اتفاق --}}
    @if ($conversation->listing?->trashed())
        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
            حُذف هذا الإعلان. المحادثة تبقى مفتوحة بينكما.
        </p>
    @endif

    <ul class="mt-5 space-y-3">
        @foreach ($conversation->messages as $message)
            @php $mine = $message->sender_id === $user->id; @endphp

            <li @class(['flex', 'justify-end' => $mine])>
                <div @class([
                    'max-w-[80%] rounded-2xl px-4 py-2.5',
                    'bg-brand text-white' => $mine,
                    'border border-line bg-white' => ! $mine,
                ])>
                    <p class="whitespace-pre-line text-sm leading-relaxed">{{ $message->body }}</p>

                    <p @class([
                        'mt-1 text-[10px] tabular-nums',
                        'text-white/70' => $mine,
                        'text-muted' => ! $mine,
                    ])>
                        {{ $message->created_at?->translatedFormat('j M · H:i') }}

                        {{-- مؤشّر القراءة يظهر لمرسل الرسالة وحده: للمستقبل
                             لا معنى له، وإظهاره للطرفين يجعله يُقرأ خطأً --}}
                        @if ($mine)
                            <span>· {{ $message->read_at ? 'قُرئت' : 'أُرسلت' }}</span>
                        @endif
                    </p>
                </div>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('account.messages.reply', $conversation) }}" class="mt-5"
          x-data="messageForm" @submit="submit($event)">
        @csrf
        {{-- معرّف الرسالة يُولَّد عند عرض النموذج: تحديث الصفحة بعد الإرسال أو
             الضغط المزدوج يرسلان المعرّف نفسه فلا تُنشأ رسالة ثانية. وold()
             يحفظه عند فشل التحقق ليعاد استخدامه في المحاولة التالية --}}
        <input type="hidden" name="client_id" x-ref="clientId" value="{{ old('client_id', \Illuminate\Support\Str::uuid()->toString()) }}">
        <label class="sr-only" for="reply-body">رسالتك</label>
        <textarea id="reply-body" name="body" rows="3" maxlength="2000" required
                  placeholder="اكتب رسالتك…"
                  class="w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">{{ old('body') }}</textarea>

        @error('body')
            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
        @enderror

        @error('client_id')
            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
        @enderror

        <button type="submit" :disabled="sending"
                class="mt-2 rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60">
            إرسال
        </button>
    </form>

    <p class="mt-4 text-[11px] leading-relaxed text-muted">
        لا تحوّل مبالغ مقدماً لمن لا تعرفه، وافحص المنتج قبل الدفع.
    </p>
</div>

@endsection