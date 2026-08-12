@extends('shared::layouts.app')

@section('title', 'توثيق رقم الجوال')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">توثيق رقم الجوال</h1>
    <p class="mt-1 text-sm text-muted">
        أُرسل رمز من ستة أرقام إلى
        <span dir="ltr" class="font-medium tabular-nums text-ink">{{ \App\Modules\Identity\Support\YemeniPhone::forDisplay($phone) }}</span>
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

    <form method="POST" action="{{ route('phone.verify') }}" class="mt-6 space-y-4">
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

        <button type="submit"
                class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
            تحقّق
        </button>
    </form>

    <form method="POST" action="{{ route('phone.resend') }}" class="mt-4">
        @csrf
        <button type="submit" class="w-full rounded-xl border border-line px-4 py-3 text-sm font-medium transition hover:bg-canvas">
            أرسل رمزاً جديداً
        </button>
    </form>

    {{-- مخرجٌ حين لا تصل الرسالة: يرسل المستخدم إلينا بدل أن ننتظر وصول
         رمزٍ إليه. والشرط Route::has لا اسم وحدة: القناة تعيش في وحدة
         مؤقتة تُحذف كاملةً، وحذفها يُخفي هذا الرابط وحده بلا أن يمسّ
         شيئاً في هذه الصفحة. --}}
    @if (Route::has('sms.challenge'))
        <p class="mt-6 text-center text-sm text-muted">
            لم تصلك الرسالة؟
            <a href="{{ route('sms.challenge') }}" class="font-medium text-brand-dark underline">
                وثّق بإرسال رسالة منك
            </a>
        </p>
    @endif
</div>

@endsection