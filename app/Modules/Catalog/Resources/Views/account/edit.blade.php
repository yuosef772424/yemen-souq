@extends('shared::layouts.app')

@section('title', 'تعديل: ' . $listing->title)

@section('content')

{{--
    نموذج مستقل لا شريك لـcreate.blade.php.

    البديل المرفوض: استخراج partial واحد يخدم النشر والتعديل. النموذجان
    يتشابهان في الحقول ويختلفان في كل ما حولها — هدف الإرسال، ووسم
    method، وقسم الصور (معاينة قبل الرفع مقابل صور محفوظة قابلة للحذف)،
    وقيمة old() الافتراضية. الـpartial المشترك سيمتلئ بشروط
    من نوع if ($mode === 'edit')، وهي أسوأ من التكرار: تُقرأ مرتين ولا تُفهم
    مرة واحدة. الاستخراج مؤجّل حتى يستقر شكل النموذجين.
--}}

<div class="mx-auto max-w-3xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold">تعديل الإعلان</h1>
        <div class="flex gap-3 text-sm">
            <a href="{{ route('listing.show', $listing) }}" class="text-brand-dark hover:underline">معاينة</a>
            <a href="{{ route('account.listings.stats', $listing) }}" class="text-brand-dark hover:underline">المشاهدات</a>
            <a href="{{ route('account.listings') }}" class="text-muted hover:underline">إعلاناتي</a>
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">تعذّر حفظ التعديل</p>
            <ul class="mt-2 list-inside list-disc space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('account.listings.update', $listing) }}" enctype="multipart/form-data"
          class="mt-6 space-y-5"
          x-data="listingForm({{ $remainingImages }})"
          x-init="priceType = '{{ old('price_type', $listing->price_type) }}'">
        @csrf
        @method('PUT')

        {{-- ═══ الأساسيات ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">تفاصيل الإعلان</legend>

            <div class="mt-2">
                <label for="title" class="block text-sm font-medium">
                    العنوان <span class="text-red-600">*</span>
                </label>
                <input id="title" name="title" type="text" value="{{ old('title', $listing->title) }}"
                       maxlength="180" required
                       class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                <p class="mt-1 text-xs text-muted">تغيير العنوان لا يغيّر رابط الإعلان — الرابط المنشور يبقى صالحاً.</p>
            </div>

            <div class="mt-4">
                <label for="description" class="block text-sm font-medium">الوصف</label>
                <textarea id="description" name="description" rows="5" maxlength="5000"
                          class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">{{ old('description', $listing->description) }}</textarea>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="category_id" class="block text-sm font-medium">
                        التصنيف <span class="text-red-600">*</span>
                    </label>
                    <select id="category_id" name="category_id" required
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                        @foreach ($categories as $category)
                            <optgroup label="{{ $category->name_ar }}">
                                @foreach ($category->children as $child)
                                    <option value="{{ $child->id }}"
                                            @selected(old('category_id', $listing->category_id) == $child->id)>
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
                        @foreach ($governorates as $governorate)
                            <optgroup label="{{ $governorate->name_ar }}">
                                @foreach ($governorate->children as $district)
                                    <option value="{{ $district->id }}"
                                            @selected(old('location_id', $listing->location_id) == $district->id)>
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
                        <option value="{{ $value }}" @selected(old('condition_type', $listing->condition_type) === $value)>
                            {{ $label }}
                        </option>
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
                           value="{{ old('price_amount', $listing->price_amount) }}"
                           class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm tabular-nums focus:border-brand focus:outline-none">
                </div>

                <div>
                    <label for="currency_code" class="block text-sm font-medium">
                        العملة <span class="text-red-600">*</span>
                    </label>
                    <select id="currency_code" name="currency_code"
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}"
                                    @selected(old('currency_code', $listing->currency_code) === $currency->code)>
                                {{ $currency->name_ar }} ({{ $currency->symbol }})
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-muted">
                        تغيير السعر يُسجَّل في تاريخ الأسعار، ويُعاد حساب قيمته الموحّدة بسعر صرف اليوم.
                    </p>
                </div>
            </div>
        </fieldset>

        {{-- ═══ الصور المحفوظة ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">الصور</legend>

            @if ($listing->images->isEmpty())
                <p class="mt-2 text-sm text-muted">لا صور لهذا الإعلان.</p>
            @else
                <ul class="mt-2 grid grid-cols-3 gap-3 sm:grid-cols-4">
                    @foreach ($listing->images as $image)
                        <li class="relative overflow-hidden rounded-lg border border-line">
                            <img src="{{ asset('storage/' . ($image->thumb_path ?? $image->path)) }}"
                                 alt="" loading="lazy" class="aspect-square w-full object-cover">

                            @if ($image->is_primary)
                                <span class="absolute bottom-0 w-full bg-ink/85 py-0.5 text-center text-[10px] font-semibold text-white">
                                    الصورة الرئيسية
                                </span>
                            @endif

                            <form method="POST"
                                  action="{{ route('account.listings.images.destroy', [$listing, $image]) }}"
                                  onsubmit="return confirm('حذف هذه الصورة؟ لا يمكن التراجع.')"
                                  class="absolute start-1 top-1">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="حذف الصورة"
                                        class="grid size-6 place-items-center rounded-md bg-white/90 text-xs font-bold text-red-700 transition hover:bg-white">
                                    ×
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-3 text-xs text-muted">
                    حذف الصورة نهائي — الملف يُمسح من القرص. وإن حُذفت الرئيسية تُرقّى التالية مكانها تلقائياً.
                </p>
            @endif

            @if ($remainingImages > 0)
                <label for="images"
                       class="mt-4 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-line px-4 py-6 text-center transition hover:border-brand hover:bg-tint">
                    <span class="text-sm font-medium">أضف صوراً</span>
                    <span class="mt-1 text-xs text-muted">
                        يتبقى {{ $remainingImages }} من {{ $maxImages }} · JPG أو PNG أو WebP · 8 ميجابايت لكل صورة
                    </span>
                    <input id="images" name="images[]" type="file" multiple accept="image/jpeg,image/png,image/webp"
                           @change="preview($event)" class="sr-only">
                </label>

                <p x-show="notice" x-cloak x-text="notice"
                   class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900"></p>

                <ul x-show="items.length" x-cloak class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <template x-for="item in items" :key="item.url">
                        <li class="overflow-hidden rounded-lg border border-dashed border-brand">
                            <img :src="item.url" alt="" class="aspect-square w-full object-cover">
                        </li>
                    </template>
                </ul>
            @else
                <p class="mt-4 rounded-lg bg-tint px-3 py-2 text-xs text-muted">
                    بلغت الحد الأقصى {{ $maxImages }} صور. احذف صورة لإضافة أخرى.
                </p>
            @endif
        </fieldset>

        {{-- ═══ التواصل ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">التواصل</legend>

            <div class="mt-2">
                <label for="contact_phone" class="block text-sm font-medium">
                    رقم الهاتف <span class="text-red-600">*</span>
                </label>
                <input id="contact_phone" name="contact_phone" type="tel" inputmode="tel" dir="ltr"
                       value="{{ old('contact_phone', $listing->contact_phone) }}" placeholder="+9677XXXXXXXX" required
                       class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm tabular-nums focus:border-brand focus:outline-none sm:w-64">
            </div>

            <label class="mt-3 flex items-center gap-2 text-sm">
                <input type="checkbox" name="hide_phone" value="1" @checked(old('hide_phone', $listing->hide_phone))
                       class="size-4 rounded border-line text-brand focus:ring-brand">
                <span>إخفاء الرقم وقبول الرسائل فقط</span>
            </label>
        </fieldset>

        <div class="flex flex-wrap gap-3">
            <button type="submit"
                    class="rounded-xl bg-brand px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
                حفظ التعديلات
            </button>
            <a href="{{ route('account.listings') }}"
               class="grid place-items-center rounded-xl border border-line px-5 text-sm font-medium transition hover:bg-tint">
                إلغاء
            </a>
        </div>
    </form>
</div>

@endsection