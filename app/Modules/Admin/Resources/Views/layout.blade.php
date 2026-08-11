{{--
    هيكل لوحة التحكم — مستقل عن shared::layouts.app بقصد.

    البديل المرفوض: التوسيع من قالب الموقع العام. ترويسة الموقع تحمل
    «أضف إعلانك» و«المفضلة» و«رسائلي» — روابط لا معنى لها للمشرف، وأسوأ:
    تجعل شاشة الإدارة تبدو كصفحة زائر فيَنسى المشرف أنه في موضع صلاحيات.
    الاختلاف البصري هنا وظيفة لا زينة.

    والمشترك بين الاثنين هو رموز التصميم (bg-canvas, text-ink, border-line)
    لا الهيكل — فالتماسك محفوظ بلا اقتران.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'لوحة التحكم') — سوق يمني</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans-arabic:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">

@php
    $nav = [
        'admin.dashboard' => 'لوحة القيادة',
        'admin.listings' => 'الإعلانات',
        'admin.reports' => 'البلاغات',
        'admin.questions' => 'الأسئلة',
    ];

    $isAdmin = \App\Modules\Admin\Enums\UserRole::of(auth()->user())->canManageUsers();
@endphp

<header class="border-b-2 border-ink bg-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-2 px-4 py-3">
        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2 shrink-0">
            <span class="grid size-9 place-items-center rounded-lg bg-ink text-xs font-bold text-white">إدارة</span>
            <span class="hidden text-sm font-semibold sm:block">لوحة التحكم</span>
        </a>

        <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="أقسام الإدارة">
            @foreach ($nav as $route => $label)
                <a href="{{ route($route) }}"
                   @class([
                       'rounded-lg px-2.5 py-2 font-medium transition',
                       'bg-tint text-ink' => request()->routeIs($route),
                       'text-muted hover:text-ink' => ! request()->routeIs($route),
                   ])>{{ $label }}</a>
            @endforeach

            @if ($isAdmin)
                <a href="{{ route('admin.users') }}"
                   @class([
                       'rounded-lg px-2.5 py-2 font-medium transition',
                       'bg-tint text-ink' => request()->routeIs('admin.users'),
                       'text-muted hover:text-ink' => ! request()->routeIs('admin.users'),
                   ])>الحسابات</a>
            @endif
        </nav>

        <div class="flex-1"></div>

        <span class="hidden text-xs text-muted sm:block">
            {{ auth()->user()->name }} · {{ \App\Modules\Admin\Enums\UserRole::of(auth()->user())->label() }}
        </span>

        <a href="{{ route('search') }}" class="rounded-lg px-2.5 py-2 text-sm font-medium text-muted transition hover:text-ink">
            الموقع
        </a>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6">
    @if (session('status'))
        <div role="status" class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div role="alert" class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ session('error') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <ul class="space-y-1">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

</body>
</html>
