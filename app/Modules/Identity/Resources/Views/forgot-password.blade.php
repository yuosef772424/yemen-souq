@extends('shared::layouts.app')

@section('title', 'استعادة كلمة المرور')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">استعادة كلمة المرور</h1>
    <p class="mt-1 text-sm text-muted">
        أدخل رقم جوالك المسجَّل، ويصلك رمز من ستة أرقام تُغيّر به كلمة مرورك.
    </p>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('password.request') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="phone" class="block text-sm font-medium">رقم الجوال</label>
            <input id="phone" name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}"
                   required autofocus dir="ltr" placeholder="0771234567"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
            أرسل الرمز
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        تذكّرتها؟ <a href="{{ route('login') }}" class="font-medium text-brand-dark underline">عد إلى الدخول</a>
    </p>
</div>

@endsection
