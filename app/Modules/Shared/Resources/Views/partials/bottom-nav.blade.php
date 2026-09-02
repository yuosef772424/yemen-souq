{{--
    شريط التنقّل السفلي — أكثر ما يجعل الموقع يُقرأ كتطبيق.

    ═══ لماذا أسفل الشاشة لا أعلاها ═══

    الإبهام يبلغ الثلث السفلي من شاشة الهاتف مرتاحاً، ويحتاج إعادة إمساك
    الجهاز ليبلغ أعلاها. ولهذا تضع كل التطبيقات الأصلية تنقّلها الرئيسي
    أسفل — والويب الذي يضعه أعلى يُقرأ فوراً كموقع مكبَّر لا تطبيق.

    ═══ خمسة لا أكثر ═══

    ما زاد على خمسة يصغّر مساحة اللمس دون الحدّ الآمن (٤٤ بكسل)، ويحوّل
    الشريط إلى صفٍّ من الرموز لا يُميَّز بعضها عن بعض. والخامس هنا زرّ
    النشر مرفوعاً في الوسط — نمطٌ متعمَّد: هو الفعل الذي تعيش عليه منصة
    الإعلانات، ورفعه يجعله أول ما تقع عليه العين والإبهام معاً.

    ═══ والمنطقة الآمنة ليست تجميلاً ═══

    `env(safe-area-inset-bottom)` هو ارتفاع شريط الإيماءات في آيفون
    الحديث. وبلاه يقع الشريط تحته فيُقصّ نصفه، أو تُبتلَع نقرات المستخدم
    على التبويب الأوسط تحديداً. والحشو **داخل** الشريط لا تحته: خلفيته
    يجب أن تمتدّ إلى حافة الزجاج، والمحتوى وحده هو ما يرتفع.

    ═══ ولماذا لا يظهر لغير المسجَّل بصورة مختلفة ═══

    التبويبات نفسها تظهر للجميع، وغير المسجَّل يُساق إلى الدخول عند
    الضغط. البديل — إخفاؤها — يجعل الشريط يتغيّر شكله بعد الدخول، فيفقد
    المستخدم الخريطة الذهنية التي بناها قبله.
--}}
@php
    $tabs = [
        [
            'label' => 'الرئيسية',
            'url' => route('search'),
            'active' => request()->routeIs('search'),
            'icon' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        ],
        [
            'label' => 'المفضلة',
            'url' => auth()->check() ? route('account.favorites') : route('login'),
            'active' => request()->routeIs('account.favorites'),
            'icon' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        ],
        [
            'label' => 'رسائلي',
            'url' => auth()->check() ? route('account.messages') : route('login'),
            'active' => request()->routeIs('account.messages*'),
            'icon' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        ],
        [
            'label' => 'حسابي',
            'url' => auth()->check() ? route('account.settings') : route('login'),
            'active' => request()->routeIs('account.settings') || request()->routeIs('merchant.*'),
            'icon' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        ],
    ];
@endphp

{{-- فاصلٌ بارتفاع الشريط: بلاه يختفي آخر سطر من كل صفحة تحته --}}
<div class="h-[4.5rem] md:hidden" style="padding-bottom: env(safe-area-inset-bottom);" aria-hidden="true"></div>

<nav aria-label="التنقّل الرئيسي"
     class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-white/95 backdrop-blur md:hidden"
     style="padding-bottom: env(safe-area-inset-bottom);">
    <div class="mx-auto grid max-w-md grid-cols-5 items-end">

        @foreach (array_slice($tabs, 0, 2) as $tab)
            @include('shared::partials.bottom-nav-tab', ['tab' => $tab])
        @endforeach

        {{-- زرّ النشر مرفوعاً: -mt-5 يُخرجه فوق حافة الشريط، وهو ما
             يجعله يُقرأ كفعلٍ رئيسي لا كتبويب خامس --}}
        <div class="grid place-items-center">
            <a href="{{ route('listing.create') }}"
               class="-mt-5 grid size-14 place-items-center rounded-full bg-brand text-white shadow-lg shadow-brand/30 transition active:scale-95"
               aria-label="أضف إعلانك">
                <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                    <path d="M12 5v14"/><path d="M5 12h14"/>
                </svg>
            </a>
        </div>

        @foreach (array_slice($tabs, 2) as $tab)
            @include('shared::partials.bottom-nav-tab', ['tab' => $tab])
        @endforeach
    </div>
</nav>
