@extends('shared::layouts.app')

@section('title', 'أضف إعلانك')

@section('content')

<div class="mx-auto max-w-3xl">
    <h1 class="text-xl font-bold">أضف إعلانك</h1>
    <p class="mt-1 text-sm text-muted">الحقول المعلَّمة بـ<span class="text-red-600">*</span> مطلوبة.</p>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">تعذّر نشر الإعلان</p>
            <ul class="mt-2 list-inside list-disc space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('listing.store') }}" enctype="multipart/form-data"
          class="mt-6 space-y-5" x-data="listingForm({{ $maxImages }})">
        @csrf

        {{-- ═══ الأساسيات ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">تفاصيل الإعلان</legend>

            <div class="mt-2">
                <label for="title" class="block text-sm font-medium">
                    العنوان <span class="text-red-600">*</span>
                </label>
                <input id="title" name="title" type="text" value="{{ old('title') }}" maxlength="180" required
                       class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                <p class="mt-1 text-xs text-muted">مثال: تويوتا كورولا 2018 وكالة — صنعاء</p>
            </div>

            <div class="mt-4">
                <label for="description" class="block text-sm font-medium">الوصف</label>
                <textarea id="description" name="description" rows="5" maxlength="5000"
                          class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">{{ old('description') }}</textarea>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="category_id" class="block text-sm font-medium">
                        التصنيف <span class="text-red-600">*</span>
                    </label>
                    <select id="category_id" name="category_id" required
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                        <option value="">اختر…</option>
                        @foreach ($categories as $category)
                            <optgroup label="{{ $category->name_ar }}">
                                @foreach ($category->children as $child)
                                    <option value="{{ $child->id }}" @selected(old('category_id') == $child->id)>
                                        {{ $child->name_ar }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="location_id" class="block text-sm font-medium">
                        الموقع <span class="text-red-600">*</span>
                    </label>
                    <select id="location_id" name="location_id" required
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                        <option value="">اختر…</option>
                        @foreach ($governorates as $governorate)
                            <optgroup label="{{ $governorate->name_ar }}">
                                @foreach ($governorate->children as $district)
                                    <option value="{{ $district->id }}" @selected(old('location_id') == $district->id)>
                                        {{ $district->name_ar }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <label for="condition_type" class="block text-sm font-medium">حالة المنتج</label>
                <select id="condition_type" name="condition_type"
                        class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none sm:w-56">
                    <option value="">غير محدد</option>
                    @foreach (['new' => 'جديد', 'like_new' => 'كالجديد', 'used' => 'مستعمل', 'for_parts' => 'قطع غيار'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('condition_type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </fieldset>

        {{-- ═══ السعر ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">السعر</legend>

            <div class="mt-2">
                <label for="price_type" class="block text-sm font-medium">نوع السعر</label>
                <select id="price_type" name="price_type" x-model="priceType"
                        class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none sm:w-56">
                    <option value="fixed">سعر محدد</option>
                    <option value="negotiable">قابل للتفاوض</option>
                    <option value="on_request">عند الطلب</option>
                    <option value="free">مجاناً</option>
                </select>
            </div>

            <div x-show="needsPrice" x-cloak class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="price_amount" class="block text-sm font-medium">
                        المبلغ <span class="text-red-600">*</span>
                    </label>
                    <input id="price_amount" name="price_amount" type="number" min="0" step="any" inputmode="decimal"
                           value="{{ old('price_amount') }}"
                           class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm tabular-nums focus:border-brand focus:outline-none">
                </div>

                <div>
                    <label for="currency_code" class="block text-sm font-medium">
                        العملة <span class="text-red-600">*</span>
                    </label>
                    <select id="currency_code" name="currency_code"
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}" @selected(old('currency_code') === $currency->code)>
                                {{ $currency->name_ar }} ({{ $currency->symbol }})
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-muted">حدّد العملة بدقة — عليها يعتمد ظهور إعلانك في الفلترة السعرية.</p>
                </div>
            </div>
        </fieldset>

        {{-- ═══ الصور ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">الصور</legend>

            <label for="images"
                   class="mt-2 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-line px-4 py-8 text-center transition hover:border-brand hover:bg-tint">
                <span class="text-sm font-medium">اختر الصور</span>
                <span class="mt-1 text-xs text-muted">
                    حتى {{ $maxImages }} صور · JPG أو PNG أو WebP · 8 ميجابايت لكل صورة
                </span>
                <input id="images" name="images[]" type="file" multiple accept="image/jpeg,image/png,image/webp"
                       @change="preview($event)" class="sr-only">
            </label>

            <p x-show="notice" x-cloak x-text="notice"
               class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900"></p>

            <ul x-show="items.length" x-cloak class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4">
                <template x-for="(item, index) in items" :key="item.url">
                    <li class="relative overflow-hidden rounded-lg border border-line">
                        <img :src="item.url" alt="" class="aspect-square w-full object-cover">
                        <span x-show="index === 0"
                              class="absolute bottom-0 w-full bg-ink/85 py-0.5 text-center text-[10px] font-semibold text-white">
                            الصورة الرئيسية
                        </span>
                    </li>
                </template>
            </ul>

            <p class="mt-3 text-xs text-muted">
                الصورة الأولى تظهر في نتائج البحث. تُحوَّل الصور تلقائياً إلى WebP،
                وتُزال منها بيانات الموقع الجغرافي حمايةً لخصوصيتك.
            </p>
        </fieldset>

        {{-- ═══ التواصل ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">التواصل</legend>

            <div class="mt-2">
                <label for="contact_phone" class="block text-sm font-medium">
                    رقم الهاتف <span class="text-red-600">*</span>
                </label>
                <input id="contact_phone" name="contact_phone" type="tel" inputmode="tel" dir="ltr"
                       value="{{ old('contact_phone') }}" placeholder="+9677XXXXXXXX" required
                       class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm tabular-nums focus:border-brand focus:outline-none sm:w-64">
            </div>

            <label class="mt-3 flex items-center gap-2 text-sm">
                <input type="checkbox" name="hide_phone" value="1" @checked(old('hide_phone'))
                       class="size-4 rounded border-line text-brand focus:ring-brand">
                <span>إخفاء الرقم وقبول الرسائل فقط</span>
            </label>
        </fieldset>

        <div class="flex gap-3">
            <button type="submit"
                    class="rounded-xl bg-brand px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
                انشر الإعلان
            </button>
            <a href="{{ route('search') }}"
               class="grid place-items-center rounded-xl border border-line px-5 text-sm font-medium transition hover:bg-tint">
                إلغاء
            </a>
        </div>
    </form>
</div>

@endsection
