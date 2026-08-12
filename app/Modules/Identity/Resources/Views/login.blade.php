@extends('shared::layouts.app')

@section('title', 'دخول')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">دخول</h1>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="identifier" class="block text-sm font-medium">رقم الجوال أو البريد</label>
            <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" required autofocus
                   dir="ltr"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <div>
            <div class="flex items-baseline justify-between gap-2">
                <label for="password" class="block text-sm font-medium">كلمة المرور</label>
                {{-- بجانب الحقل لا في أسفل الصفحة: من نسي كلمته يكتشف ذلك
                     عند هذا الحقل بالضبط، لا بعد أن يقرأ الصفحة كلها --}}
                <a href="{{ route('password.request') }}" class="text-xs text-muted underline hover:text-ink">
                    نسيتها؟
                </a>
            </div>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" value="1" class="rounded border-line">
            <span>أبقني مسجَّلاً</span>
        </label>

        <button type="submit"
                class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
            دخول
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        لا حساب لك؟ <a href="{{ route('register') }}" class="font-medium text-brand-dark underline">أنشئ حساباً</a>
    </p>
</div>

@endsection