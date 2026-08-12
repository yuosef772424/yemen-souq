@extends('shared::layouts.app')

@section('title', 'كلمة مرور جديدة')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">كلمة مرور جديدة</h1>
    <p class="mt-1 text-sm text-muted">
        إن كان
        <span dir="ltr" class="font-medium tabular-nums text-ink">{{ \App\Modules\Identity\Support\YemeniPhone::forDisplay($phone) }}</span>
        مسجَّلاً فقد وصله رمز من ستة أرقام.
    </p>

    @if (session('demo_code'))
        <div role="status" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">بيئة تجريبية — لا رسائل تُرسَل فعلاً</p>
            <p class="mt-2">الرمز: <span dir="ltr" class="text-lg font-bold tabular-nums">{{ session('demo_code') }}</span></p>
            <p class="mt-2 text-xs">في الإنتاج يوصّل الرمز مزوّد رسائل ولا يظهر هنا إطلاقاً.</p>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('password.reset') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="code" class="block text-sm font-medium">الرمز</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                   maxlength="6" required autofocus dir="ltr"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-center text-lg tracking-[0.4em] tabular-nums focus:border-brand focus:outline-none">
            @if ($secondsRemaining > 0)
                <p class="mt-1 text-xs text-muted">تنتهي صلاحيته بعد {{ ceil($secondsRemaining / 60) }} دقيقة.</p>
            @endif
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">كلمة المرور الجديدة</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
            <p class="mt-1 text-xs text-muted">ثمانية أحرف على الأقل.</p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">تأكيد كلمة المرور</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
            غيّر كلمة المرور
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        لم يصلك الرمز؟
        <a href="{{ route('password.request') }}" class="font-medium text-brand-dark underline">أعد المحاولة</a>
    </p>
</div>

@endsection
