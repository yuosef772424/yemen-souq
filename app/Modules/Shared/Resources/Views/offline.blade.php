@extends('shared::layouts.app')

@section('title', 'لا يوجد اتصال')

@section('content')

<div class="mx-auto max-w-sm py-16 text-center">
    <div class="mx-auto grid size-16 place-items-center rounded-2xl bg-tint">
        <svg class="size-8 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M2 2l20 20"/>
            <path d="M8.5 16.5a5 5 0 0 1 7 0"/>
            <path d="M5 12.9a10 10 0 0 1 5.2-2.7"/>
            <path d="M2 8.8a15 15 0 0 1 4.2-2.6"/>
            <path d="M17.8 11.2a10 10 0 0 1 1.2.9"/>
            <path d="M22 8.8a15 15 0 0 0-5.2-3"/>
            <path d="M12 20h.01"/>
        </svg>
    </div>

    <h1 class="mt-5 text-lg font-bold">لا يوجد اتصال بالإنترنت</h1>
    <p class="mt-2 text-sm leading-relaxed text-muted">
        تعذّر الوصول إلى سوق يمني. تحقّق من اتصالك ثم أعد المحاولة —
        لن تفقد شيئاً مما كنت تتصفّحه.
    </p>

    {{-- إعادة تحميل لا رابط: الرابط يحتاج شبكةً هي الغائبة أصلاً --}}
    <button type="button" onclick="location.reload()"
            class="mt-6 w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
        أعد المحاولة
    </button>
</div>

@endsection
