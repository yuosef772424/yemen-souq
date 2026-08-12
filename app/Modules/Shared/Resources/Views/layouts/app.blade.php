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

<header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center gap-2 px-4 py-3">
        <a href="{{ route('search') }}" class="flex items-center gap-2 shrink-0">
            <span class="grid size-9 place-items-center rounded-lg bg-ink text-sm font-bold text-white">سوق</span>
            <span class="hidden text-base font-semibold sm:block">سوق يمني</span>
        </a>

        <div class="flex-1"></div>

        @auth
            {{--
                روابط بلا عدّاد غير مقروء بقصد.

                شارة «3 رسائل جديدة» في الترويسة تعني استعلام COUNT في كل
                صفحة من الموقع لكل مستخدم مسجَّل — بما فيها صفحات لا علاقة
                لها بالرسائل. الرقم يُعرض في صفحة الرسائل نفسها حيث يُقرأ.

                والقاعدة نفسها تسري على الإشعارات، وهي أشدّ إغراءً بالمخالفة:
                جرس بلا رقم يبدو ناقصاً. لكن الرقم هنا استعلامٌ ثانٍ على
                app_notifications في كل صفحة — والمقابل رقمٌ يقرؤه المستخدم
                مرة ثم يفتح الصفحة على أي حال.

                موضع العدّاد الصحيح هو الدفع في التطبيق: هناك يصل الرقم مع
                الإشعار بلا استعلام، وهناك يُقرأ والشاشة مقفلة.
            --}}
            <nav class="flex items-center gap-1 text-sm" aria-label="حسابي">
                <a href="{{ route('account.listings') }}"
                   class="rounded-lg px-2.5 py-2 font-medium text-muted transition hover:text-ink">إعلاناتي</a>
                {{--
                    «لوحتي» قبل «إعلاناتي» في الأهمية وبعدها في الترتيب بقصد:
                    من يدخل يبحث عن إعلاناته أولاً، ويكتشف اللوحة ثانياً.
                --}}
                <a href="{{ route('merchant.dashboard') }}"
                   class="rounded-lg px-2.5 py-2 font-medium text-muted transition hover:text-ink">لوحتي</a>
                <a href="{{ route('account.messages') }}"
                   class="rounded-lg px-2.5 py-2 font-medium text-muted transition hover:text-ink">رسائلي</a>
                <a href="{{ route('account.notifications') }}"
                   class="rounded-lg px-2.5 py-2 font-medium text-muted transition hover:text-ink">الإشعارات</a>
                <a href="{{ route('account.favorites') }}"
                   class="hidden rounded-lg px-2.5 py-2 font-medium text-muted transition hover:text-ink sm:block">المفضلة</a>
            </nav>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-2.5 py-2 text-sm font-medium text-muted transition hover:text-ink">
                    خروج
                </button>
            </form>
        @else
            <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink">
                دخول
            </a>
        @endauth

        <a href="{{ route('listing.create') }}" class="shrink-0 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
            أضف إعلانك
        </a>
    </div>
</header>

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
        <p>مشروع تجريبي مستقل لعرض مقاربة في البحث والفلترة داخل السياق اليمني. ليس نسخة من أي منصة قائمة.</p>
        <p class="mt-2">أسعار الصرف المستخدمة تجريبية وليست أسعاراً حقيقية.</p>
    </div>
</footer>

</body>
</html>
