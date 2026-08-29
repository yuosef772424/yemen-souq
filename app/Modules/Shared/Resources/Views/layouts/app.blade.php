<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'سوق يمني — إعلانات مبوبة')</title>
    <meta name="description" content="@yield('description', 'إعلانات مبوبة في اليمن — بحث دقيق يفرّق بين الريال اليمني والسعودي والدولار.')">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans-arabic:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">

@include('shared::partials.header')

<main class="mx-auto max-w-6xl px-4 py-6">
    @if (session('status'))
        <div role="status" class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
            <p class="font-medium">{{ session('status') }}</p>
            @foreach (session('warnings', []) as $warning)
                <p class="mt-1 text-xs text-green-800">{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    {{-- محاولة مرفوضة ليست نجاحاً --}}
    @if (session('error'))
        <div role="alert" class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ session('error') }}
        </div>
    @endif

    @yield('content')
</main>

<footer class="mt-16 border-t border-line py-8">
    <div class="mx-auto max-w-6xl px-4 text-sm text-muted">
        <p>سوق يمني © {{ now()->year }} — جميع الحقوق محفوظة.</p>
    </div>
</footer>

</body>
</html>
