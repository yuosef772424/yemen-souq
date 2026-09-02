<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- viewport-fit=cover شرط لعمل env(safe-area-inset-*): بلاه يترك
         المتصفح أشرطةً سوداء حول الشقّ بدل أن يمدّ الصفحة تحته، فتفقد
         الواجهة طابع التطبيق تماماً على الأجهزة الحديثة --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <title>@yield('title', 'سوق يمني — إعلانات مبوبة')</title>
    <meta name="description" content="@yield('description', 'إعلانات مبوبة في اليمن — بحث دقيق يفرّق بين الريال اليمني والسعودي والدولار.')">

    {{-- ═══ التثبيت على الشاشة الرئيسية ═══
         theme-color أبيض لا تيل: هو لون شريط حالة الهاتف، وترويستنا
         بيضاء — فأي لون آخر يُنتج خطاً فاصلاً يكشف أنه متصفح لا تطبيق --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="سوق يمني">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="icon" href="/icons/favicon-32.png" sizes="32x32">

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

{{-- التذييل مخفيّ على الجوال: شريط التنقّل السفلي يشغل مكانه، وتذييلٌ
     تحته يعني تمريراً إلى نصٍّ لا يقرؤه أحد على الهاتف --}}
<footer class="mt-16 hidden border-t border-line py-8 md:block">
    <div class="mx-auto max-w-6xl px-4 text-sm text-muted">
        <p>سوق يمني © {{ now()->year }} — جميع الحقوق محفوظة.</p>
    </div>
</footer>

@include('shared::partials.bottom-nav')

{{-- تسجيل عامل الخدمة بعد load لا قبله: تسجيلٌ مبكر يزاحم تحميل
     الصفحة نفسها على اتصال بطيء — وهو الاتصال الغالب هنا --}}
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function () {
                // فشل التسجيل لا يُعطّل شيئاً: الموقع يعمل بلا عامل خدمة
            });
        });
    }
</script>

</body>
</html>
