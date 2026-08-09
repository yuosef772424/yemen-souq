@extends('shared::layouts.app')

@section('title', 'حساب جديد')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">حساب جديد</h1>
    <p class="mt-1 text-sm text-muted">تحتاج حساباً لنشر إعلان. التصفّح والبحث بلا حساب.</p>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">الاسم <span class="text-red-600">*</span></label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium">رقم الجوال <span class="text-red-600">*</span></label>
            <input id="phone" name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" required
                   placeholder="0771234567" dir="ltr"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm tabular-nums focus:border-brand focus:outline-none">
            <p class="mt-1 text-xs text-muted">سيُرسل إليه رمز تحقق. هو أيضاً رقم التواصل في إعلاناتك.</p>
        </div>

        <div>
            <label for="email" class="block text-sm font-medium">البريد الإلكتروني <span class="text-red-600">*</span></label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required dir="ltr"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">كلمة المرور <span class="text-red-600">*</span></label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
            <p class="mt-1 text-xs text-muted">ثمانية أحرف على الأقل.</p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">تأكيد كلمة المرور <span class="text-red-600">*</span></label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
            أنشئ الحساب
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        لديك حساب؟ <a href="{{ route('login') }}" class="font-medium text-brand-dark underline">دخول</a>
    </p>
</div>

@endsection