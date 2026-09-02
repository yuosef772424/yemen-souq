{{--
    الترويسة المشتركة.

    **لماذا البحث والأقسام هنا لا في صفحة البحث:** كانا محبوسين في
    `search::index`، فمن يقف على صفحة إعلان أو على حسابه لا يملك مدخلاً
    للبحث إلا العودة للرئيسية أولاً. منصات الإعلانات المبوّبة تضع الاثنين
    في الترويسة لأن نية البحث تُولد في أي صفحة لا في واحدة.

    البيانات من `Shared\Services\Navigation` — استعلام مخزَّن دائماً لا
    استعلام في كل طلب.
--}}
@inject('nav', 'App\Modules\Shared\Services\Navigation')

@php
    // المعاملات تُقرأ قسريّاً كقيم مفردة: `?q[]=شقة` يجعل request('q') مصفوفةً
    // تُسقط الصفحة عند طباعتها. الحارس نفسه قائم في SearchCriteria، والترويسة
    // تقرأ الطلب مباشرةً فتحتاجه لنفسها — وSearchInputGuardsTest يحرس الاثنين.
    $query = is_string(request('q')) ? request('q') : null;
    $activeCategory = is_numeric(request('category')) ? (int) request('category') : null;
    $activeLocation = is_numeric(request('location')) ? (int) request('location') : null;
    $locationName = $activeLocation
        ? $nav->governorates()->firstWhere('id', $activeLocation)?->name_ar
        : null;
@endphp

<header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">

    {{-- ═══ الصف الرئيسي: شعار + بحث + حساب ═══ --}}
    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2.5 px-4 py-2.5">

        <a href="{{ route('search') }}" class="flex shrink-0 items-center gap-2">
            <span class="grid size-9 place-items-center rounded-lg bg-ink text-sm font-bold text-white">سوق</span>
            <span class="hidden text-base font-semibold sm:block">سوق يمني</span>
        </a>

        {{-- البحث: يأخذ السطر كاملاً على الجوال، ويتوسّط الترويسة على ما فوقه.
             `order` يضعه تحت الشعار والأزرار على الضيّق بلا تكرار العنصر --}}
        <form method="GET" action="{{ route('search') }}"
              class="order-last flex w-full min-w-0 items-center gap-2 md:order-none md:flex-1">
            <label class="sr-only" for="site-search">ابحث في الإعلانات</label>
            <div class="relative min-w-0 flex-1">
                {{-- بلا `maxlength` من ثابت وحدة البحث: الترويسة في Shared، وShared
                     لا تعرف وحدةً أخرى (يحرسه ModuleContractTest). الحدّ يفرضه
                     SearchCriteria على الخادم، وهو الموضع الملزِم على أي حال --}}
                <input id="site-search" name="q" type="search" value="{{ $query }}"
                       placeholder="ابحث عن سيارة، شقة، جوال…" autocomplete="off"
                       class="w-full rounded-xl border border-line bg-canvas py-2.5 pe-4 ps-10 text-sm placeholder:text-muted focus:border-brand focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand/20">
                <svg class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                </svg>
            </div>

            {{-- التصنيف والمحافظة يبقيان مع البحث الجديد: من ضيّق نطاقه
                 لا يقصد توسيعه بمجرد كتابة كلمة --}}
            @if ($activeCategory)
                <input type="hidden" name="category" value="{{ $activeCategory }}">
            @endif
            @if ($activeLocation)
                <input type="hidden" name="location" value="{{ $activeLocation }}">
            @endif

            <button type="submit"
                    class="shrink-0 rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark">
                بحث
            </button>
        </form>

        <div class="flex items-center gap-1">
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
                <nav class="flex items-center" aria-label="حسابي">
                    @foreach ([
                        ['route' => 'account.favorites', 'label' => 'المفضلة', 'icon' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>'],
                        ['route' => 'account.listings', 'label' => 'إعلاناتي', 'icon' => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>'],
                        ['route' => 'account.notifications', 'label' => 'الإشعارات', 'icon' => '<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="M21 8.5c0-3.6-2.9-6.5-6.5-6.5h-5C5.9 2 3 4.9 3 8.5c0 6-2 7.5-2 7.5h22s-2-1.5-2-7.5"/>'],
                        ['route' => 'account.messages', 'label' => 'رسائلي', 'icon' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>'],
                    ] as $item)
                        <a href="{{ route($item['route']) }}" title="{{ $item['label'] }}"
                           class="rounded-lg p-2 text-muted transition hover:bg-tint hover:text-ink">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                {!! $item['icon'] !!}
                            </svg>
                            <span class="sr-only">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </nav>

                {{-- الحساب والخروج خلف قائمة: يُفتحان مرةً كل عدة أشهر، ووجودهما
                     ظاهرَين يزاحم ما يُفتح كل زيارة --}}
                <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
                    <button type="button" @click="open = !open" :aria-expanded="open"
                            class="flex items-center gap-1 rounded-lg p-1.5 transition hover:bg-tint">
                        @if (auth()->user()->avatarUrl())
                            <img src="{{ auth()->user()->avatarUrl() }}" alt="" class="size-7 rounded-full object-cover">
                        @else
                            <span class="grid size-7 place-items-center rounded-full bg-canvas text-xs font-bold text-muted"
                                  aria-hidden="true">{{ mb_substr(auth()->user()->displayName(), 0, 1) }}</span>
                        @endif
                        <svg class="size-3.5 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        <span class="sr-only">قائمة الحساب</span>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity.duration.150ms @click.outside="open = false"
                         class="absolute end-0 top-full z-40 mt-1 w-48 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-lg">
                        <p class="truncate px-3 py-2 text-xs text-muted">{{ auth()->user()->displayName() }}</p>
                        <a href="{{ route('merchant.dashboard') }}" class="block px-3 py-2 text-sm transition hover:bg-tint">لوحتي</a>
                        <a href="{{ route('account.settings') }}" class="block px-3 py-2 text-sm transition hover:bg-tint">حسابي</a>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-line">
                            @csrf
                            <button type="submit" class="w-full px-3 py-2 text-start text-sm text-muted transition hover:bg-tint hover:text-ink">
                                خروج
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <a href="{{ route('login') }}"
                   class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink">دخول</a>
            @endauth

            {{-- تذكير رقم غير موثَّق — نمطٌ شائع في منصّات الإعلانات
                 المبوّبة (وثّق حسابك في دوبيزل مثلاً): ظاهرٌ باستمرار لا
                 مرة عند النشر، فالمستخدم يوثّق حين يناسبه لا حين يُضطرّ --}}
            @auth
                @unless (auth()->user()->hasVerifiedPhone())
                    <a href="{{ route('phone.verify') }}"
                       class="hidden shrink-0 items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-900 transition hover:bg-amber-100 sm:flex">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 9v4"/><path d="M12 17h.01"/>
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                        </svg>
                        وثّق رقمك
                    </a>
                @endunless
            @endauth

            <a href="{{ route('listing.create') }}"
               class="shrink-0 rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:px-4">
                <span class="hidden sm:inline">أضف إعلانك</span>
                <span class="sm:hidden">أضف</span>
            </a>
        </div>
    </div>

    {{-- ═══ صف التنقّل: المحافظة + كل الأقسام + اختصارات ═══ --}}
    <div class="border-t border-line bg-white">
        <div class="mx-auto flex max-w-6xl items-center gap-1 overflow-x-auto px-4 py-1.5 text-sm">

            {{-- المحافظة أولاً: في الإعلانات المبوّبة الموقع فلتر قبل أن يكون
                 تفصيلاً — لا أحد يشتري أريكة في محافظة لا يبلغها --}}
            <div class="shrink-0" x-data="headerDropdown()" @keydown.escape="close()">
                <button type="button" x-ref="trigger" @click="toggle()" :aria-expanded="open"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 font-medium transition hover:bg-tint">
                    <svg class="size-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>
                    </svg>
                    {{ $locationName ?? 'كل المحافظات' }}
                    <svg class="size-3 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>

                {{-- خارج الشريط القابل للتمرير عبر x-teleport — انظر تعليق
                     headerDropdown في app.js لسبب وجوده --}}
                <template x-teleport="body">
                    {{-- @click.outside مقارنةً بموضع الزرّ لا العنصر الحاوي: بعد
                         الترحيل الزرّ لم يعد أصلاً DOM للوحة، فبلا هذا الشرط
                         يُحسَب نقر الزرّ نفسه "خارجاً" ويُغلقها في نفس النقرة
                         التي فتحتها --}}
                    {{-- بلا x-cloak: عنصر مُرحَّل بـx-teleport يُستنسَخ وقت
                         معالجة التوجيه لا في المرور الأول الذي يُزيل x-cloak
                         من شجرة DOM — فيبقى الوسم على النسخة المُرحَّلة إلى
                         الأبد ويهزم x-show بقاعدة !important. وغير محتاج
                         أصلاً: اللوحة تبدأ open:false فتُخفيها x-show نفسها
                         قبل أي عرض --}}
                    <div x-show="open"
                         @click.outside="if (!$refs.trigger.contains($event.target)) close()"
                         :style="panelStyle"
                         class="fixed z-40 max-h-80 w-56 overflow-y-auto rounded-xl border border-line bg-white py-1 shadow-lg">
                        <a href="{{ route('search', request()->except(['location', 'page'])) }}"
                           @class(['block px-3 py-2 text-sm transition hover:bg-tint', 'font-semibold text-brand-dark' => ! $activeLocation])>
                            كل المحافظات
                        </a>
                        @foreach ($nav->governorates() as $governorate)
                            <a href="{{ route('search', [...request()->except(['page']), 'location' => $governorate->id]) }}"
                               @class(['block px-3 py-2 text-sm transition hover:bg-tint', 'font-semibold text-brand-dark' => $activeLocation === $governorate->id])>
                                {{ $governorate->name_ar }}
                            </a>
                        @endforeach
                    </div>
                </template>
            </div>

            <span class="h-5 w-px shrink-0 bg-line" aria-hidden="true"></span>

            {{-- كل الأقسام: لوحة بالتصنيفات الجذرية وأبنائها --}}
            <div class="shrink-0" x-data="{ open: false }" @keydown.escape="open = false">
                <button type="button" @click="open = !open" :aria-expanded="open"
                        class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 font-medium transition hover:bg-tint">
                    <svg class="size-4 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/>
                        <rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>
                    </svg>
                    كل الأقسام
                    <svg class="size-3 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>

                <div x-show="open" x-cloak x-transition.opacity.duration.150ms @click.outside="open = false"
                     class="absolute inset-x-0 z-40 mx-auto mt-1 max-h-[70vh] max-w-6xl overflow-y-auto rounded-xl border border-line bg-white p-4 shadow-lg">
                    <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($nav->categories() as $category)
                            <div>
                                <a href="{{ route('search', ['category' => $category->id]) }}"
                                   class="flex items-center gap-2 font-semibold transition hover:text-brand-dark">
                                    @include('shared::partials.category-icon', ['icon' => $category->icon, 'class' => 'size-4 text-brand'])
                                    {{ $category->name_ar }}
                                </a>
                                <ul class="mt-1.5 space-y-0.5 ps-6">
                                    @foreach ($category->children as $child)
                                        <li>
                                            <a href="{{ route('search', ['category' => $child->id]) }}"
                                               class="block py-0.5 text-xs text-muted transition hover:text-ink">
                                                {{ $child->name_ar }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <span class="h-5 w-px shrink-0 bg-line" aria-hidden="true"></span>

            @foreach ($nav->shortcuts() as $shortcut)
                <a href="{{ route('search', ['category' => $shortcut->id]) }}"
                   @class([
                       'shrink-0 rounded-lg px-2.5 py-1.5 font-medium transition hover:bg-tint',
                       'bg-tint text-brand-dark' => $activeCategory === $shortcut->id,
                       'text-muted hover:text-ink' => $activeCategory !== $shortcut->id,
                   ])>
                    {{ $shortcut->name_ar }}
                </a>
            @endforeach
        </div>
    </div>
</header>
