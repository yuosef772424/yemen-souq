@extends('shared::layouts.app')

@section('title', 'إعدادات الحساب')

{{--
    أربعة نماذج في صفحة، وكلٌّ يحمل حزمة أخطائه.

    `$errors->getBag('profile')` لا `$errors` المجرَّدة: النماذج تُرسَل
    إلى مسارات مختلفة وتعود كلها إلى هنا، وبحزمة واحدة يظهر خطأ كلمة
    المرور فوق حقل الاسم — ويظنّ المستخدم أن اسمه هو المرفوض.

    وأسماء الحزم مضبوطة في `$errorBag` داخل كل FormRequest.
--}}

@section('content')

@php
    $profileErrors = $errors->getBag('profile');
    $passwordErrors = $errors->getBag('password');
    $phoneErrors = $errors->getBag('phone');
    $deleteErrors = $errors->getBag('deleteAccount');
    $field = 'mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none';
    $label = 'block text-sm font-medium';
    $card = 'rounded-2xl border border-line bg-white p-5';
    $primary = 'rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark';
@endphp

<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h1 class="text-xl font-bold">إعدادات الحساب</h1>
        <p class="mt-1 text-sm text-muted">بياناتك، ورقمك، وكلمة مرورك — وإغلاق الحساب.</p>
    </div>

    {{-- ─── البيانات ─── --}}
    <section class="{{ $card }}">
        <h2 class="text-base font-semibold">بياناتي</h2>

        @if ($profileErrors->any())
            <div role="alert" class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <ul class="space-y-1">
                    @foreach ($profileErrors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- enctype ضروري: بلاه لا يصل الملف إلى الخادم أصلاً، ويُحفظ
             الباقي بلا خطأ فتبدو الصورة وحدها معطوبة --}}
        <form method="POST" action="{{ route('account.profile.update') }}" enctype="multipart/form-data"
              class="mt-4 space-y-4">
            @csrf
            @method('PUT')

            {{-- الصورة: معاينة فورية قبل الرفع، بلا طلب إلى الخادم --}}
            <div x-data="{ preview: null }" class="flex items-center gap-4">
                <template x-if="preview">
                    <img :src="preview" alt="" class="size-20 shrink-0 rounded-full object-cover">
                </template>

                <template x-if="!preview">
                    <div class="shrink-0">
                        @if ($user->avatarUrl())
                            <img src="{{ $user->avatarUrl() }}" alt="صورتك الحالية"
                                 class="size-20 rounded-full object-cover">
                        @else
                            <span class="grid size-20 place-items-center rounded-full bg-canvas text-xl font-bold text-muted"
                                  aria-hidden="true">{{ mb_substr($profile?->display_name ?? $user->name, 0, 1) }}</span>
                        @endif
                    </div>
                </template>

                <div class="min-w-0 flex-1">
                    <label for="avatar" class="{{ $label }}">صورة الحساب</label>
                    <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp"
                           @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
                           class="mt-1 w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-canvas file:px-3 file:py-2 file:text-sm file:font-medium">
                    <p class="mt-1 text-xs text-muted">تُقصّ مربّعةً وتُحوَّل إلى WebP، وتُزال منها بيانات الموقع.</p>

                    @if ($user->avatarUrl())
                        <label class="mt-2 flex items-center gap-2 text-xs">
                            <input type="checkbox" name="remove_avatar" value="1" class="size-4 rounded border-line">
                            <span>احذف صورتي الحالية</span>
                        </label>
                    @endif
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="{{ $label }}">الاسم</label>
                    <input id="name" name="name" required minlength="3" maxlength="120"
                           value="{{ old('name', $user->name) }}" class="{{ $field }}">
                    <p class="mt-1 text-xs text-muted">اسم الحساب — لا يُعرض للمشترين.</p>
                </div>

                <div>
                    <label for="display_name" class="{{ $label }}">الاسم المعروض</label>
                    <input id="display_name" name="display_name" required minlength="3" maxlength="120"
                           value="{{ old('display_name', $profile?->display_name ?? $user->name) }}" class="{{ $field }}">
                    <p class="mt-1 text-xs text-muted">هذا ما يراه من يفتح إعلانك.</p>
                </div>
            </div>

            <div>
                <label for="email" class="{{ $label }}">البريد الإلكتروني</label>
                <input id="email" name="email" type="email" required dir="ltr"
                       value="{{ old('email', $user->email) }}" class="{{ $field }}">
            </div>

            <div>
                <label for="bio" class="{{ $label }}">نبذة</label>
                <textarea id="bio" name="bio" rows="3" maxlength="500"
                          class="{{ $field }}">{{ old('bio', $profile?->bio) }}</textarea>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="location_id" class="{{ $label }}">موقعي</label>
                    <select id="location_id" name="location_id" class="{{ $field }}">
                        <option value="">— لم أحدّد —</option>
                        @foreach ($governorates as $governorate)
                            <option value="{{ $governorate->id }}"
                                @selected(old('location_id', $profile?->location_id) == $governorate->id)>
                                {{ $governorate->name_ar }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-muted">يملأ فلتر البحث ونموذج النشر مسبقاً.</p>
                </div>

                <div>
                    <label for="preferred_currency" class="{{ $label }}">العملة المفضّلة</label>
                    <select id="preferred_currency" name="preferred_currency" class="{{ $field }}">
                        <option value="">— لم أحدّد —</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}"
                                @selected(old('preferred_currency', $profile?->preferred_currency) === $currency->code)>
                                {{ $currency->name_ar }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- x-data محلي: إظهار حقل اسم النشاط دون طلب إلى الخادم --}}
            <div x-data="{ business: {{ old('is_business', $profile?->is_business) ? 'true' : 'false' }} }">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_business" value="1" x-model="business"
                           class="size-4 rounded border-line">
                    <span>حسابي تجاري (معرض أو محل)</span>
                </label>

                <div x-show="business" x-cloak class="mt-3">
                    <label for="business_name" class="{{ $label }}">اسم النشاط</label>
                    <input id="business_name" name="business_name" maxlength="160"
                           value="{{ old('business_name', $profile?->business_name) }}" class="{{ $field }}">
                </div>
            </div>

            <button type="submit" class="{{ $primary }}">حفظ البيانات</button>
        </form>
    </section>

    {{-- ─── الرقم ─── --}}
    <section class="{{ $card }}">
        <h2 class="text-base font-semibold">رقم الجوال</h2>
        <p class="mt-1 text-sm text-muted">
            الرقم الحالي:
            <span dir="ltr" class="font-medium tabular-nums text-ink">
                {{ \App\Modules\Identity\Support\YemeniPhone::forDisplay($user->phone) ?? '—' }}
            </span>
            @if ($user->hasVerifiedPhone())
                <span class="rounded-md bg-green-50 px-1.5 py-0.5 text-xs font-medium text-green-800">موثَّق</span>
            @else
                <a href="{{ route('phone.verify') }}" class="text-xs font-medium text-brand-dark underline">غير موثَّق — وثّقه</a>
            @endif
        </p>

        @if ($phoneErrors->any())
            <div role="alert" class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <ul class="space-y-1">
                    @foreach ($phoneErrors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('account.phone.update') }}" class="mt-4 space-y-4">
            @csrf
            @method('PUT')

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="phone" class="{{ $label }}">الرقم الجديد</label>
                    <input id="phone" name="phone" type="tel" inputmode="tel" required dir="ltr"
                           placeholder="0771234567" class="{{ $field }}">
                </div>

                <div>
                    <label for="phone_password" class="{{ $label }}">كلمة المرور</label>
                    <input id="phone_password" name="current_password" type="password" required
                           autocomplete="current-password" class="{{ $field }}">
                </div>
            </div>

            <p class="text-xs text-muted">تغيير الرقم يُلغي التوثيق، ويُرسَل رمز جديد إلى الرقم الجديد.</p>

            <button type="submit" class="{{ $primary }}">تغيير الرقم</button>
        </form>
    </section>

    {{-- ─── كلمة المرور ─── --}}
    <section class="{{ $card }}">
        <h2 class="text-base font-semibold">كلمة المرور</h2>

        @if ($passwordErrors->any())
            <div role="alert" class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <ul class="space-y-1">
                    @foreach ($passwordErrors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('account.password.update') }}" class="mt-4 space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="current_password" class="{{ $label }}">كلمة المرور الحالية</label>
                <input id="current_password" name="current_password" type="password" required
                       autocomplete="current-password" class="{{ $field }}">
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="{{ $label }}">الجديدة</label>
                    <input id="password" name="password" type="password" required minlength="8"
                           autocomplete="new-password" class="{{ $field }}">
                </div>

                <div>
                    <label for="password_confirmation" class="{{ $label }}">تأكيد الجديدة</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           autocomplete="new-password" class="{{ $field }}">
                </div>
            </div>

            <button type="submit" class="{{ $primary }}">تغيير كلمة المرور</button>
        </form>
    </section>

    {{-- ─── صفحات أخرى ───
         بـRoute::has لا بأسماء مباشرة: الإشعارات والتحليلات وحدتان
         قائمتان بذاتهما، وحذف أيّهما يجب أن يُخفي رابطها وحده بلا أن
         يكسر هذه الصفحة. نفس شرط رابط القناة العكسية في /verify-phone. --}}
    @if (Route::has('account.notifications.preferences') || Route::has('account.privacy'))
        <section class="{{ $card }}">
            <h2 class="text-base font-semibold">إعدادات أخرى</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @if (Route::has('account.notifications.preferences'))
                    <li>
                        <a href="{{ route('account.notifications.preferences') }}" class="font-medium text-brand-dark underline">
                            تفضيلات الإشعارات
                        </a>
                        <span class="text-muted">— ما يصلك، وعلى أي قناة.</span>
                    </li>
                @endif
                @if (Route::has('account.privacy'))
                    <li>
                        <a href="{{ route('account.privacy') }}" class="font-medium text-brand-dark underline">
                            الخصوصية والتتبّع
                        </a>
                        <span class="text-muted">— الانسحاب من قياس الاستعمال.</span>
                    </li>
                @endif
            </ul>
        </section>
    @endif

    {{-- ─── الإغلاق ─── --}}
    <section class="rounded-2xl border border-red-200 bg-red-50/40 p-5">
        <h2 class="text-base font-semibold text-red-900">إغلاق الحساب</h2>
        <p class="mt-1 text-sm text-red-900/80">
            تُخفى إعلاناتك من السوق، ويتوقف الدخول. محادثاتك تبقى عند الطرف الآخر — لا نملك حذف
            نصف محادثة.
        </p>

        @if ($deleteErrors->any())
            <div role="alert" class="mt-3 rounded-xl border border-red-300 bg-white p-3 text-sm text-red-800">
                <ul class="space-y-1">
                    @foreach ($deleteErrors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('account.destroy') }}" class="mt-4 space-y-4"
              onsubmit="return confirm('إغلاق الحساب نهائي — لا يمكنك التراجع بنفسك. متأكد؟')">
            @csrf
            @method('DELETE')

            <div class="sm:max-w-xs">
                <label for="delete_password" class="{{ $label }} text-red-900">أكّد بكلمة المرور</label>
                <input id="delete_password" name="current_password" type="password" required
                       autocomplete="current-password" class="{{ $field }}">
            </div>

            <button type="submit"
                    class="rounded-xl border border-red-300 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-100">
                إغلاق حسابي
            </button>
        </form>
    </section>

</div>

@endsection
