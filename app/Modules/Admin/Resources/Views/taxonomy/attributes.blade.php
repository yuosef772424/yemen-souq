@extends('admin::layout')

@section('title', 'الخصائص')

@section('content')

@php
    $types = [
        'number' => 'رقم',
        'option' => 'قائمة خيارات',
        'boolean' => 'نعم / لا',
        'string' => 'نص',
        'date' => 'تاريخ',
    ];
@endphp

<div class="flex flex-wrap items-baseline justify-between gap-3">
    <h1 class="text-lg font-semibold">الخصائص</h1>
    <p class="text-sm text-muted">{{ $attributes->count() }} خاصية</p>
</div>

<p class="mt-1 text-sm text-muted">
    الخاصية المربوطة بتصنيف تظهر فوراً في نموذج النشر وفي فلاتر البحث — بلا نشر كود.
</p>

<div class="mt-5 grid gap-5 lg:grid-cols-[1fr_22rem]">

    {{-- ═══ القائمة ═══ --}}
    <div class="space-y-3">
        @foreach ($attributes as $attribute)
            <div x-data="{ open: false }" class="rounded-2xl border border-line bg-white p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $attribute->name_ar }}</span>
                            <span class="rounded-md bg-tint px-2 py-0.5 text-xs text-muted">
                                {{ $types[$attribute->data_type] ?? $attribute->data_type }}
                            </span>
                            @if ($attribute->unit)
                                <span class="text-xs text-muted">({{ $attribute->unit }})</span>
                            @endif
                        </div>
                        <div class="mt-0.5 text-xs text-muted" dir="ltr">{{ $attribute->attr_key }}</div>
                    </div>

                    <div class="text-xs text-muted">
                        {{ $attribute->categories_count }} تصنيفاً
                        @if ($attribute->data_type === 'option')
                            · {{ $attribute->options->count() }} خياراً
                        @endif
                    </div>

                    <button type="button" @click="open = ! open"
                            class="rounded-lg border border-line px-2.5 py-1 text-xs font-medium transition hover:bg-tint">
                        تفاصيل
                    </button>
                </div>

                <div x-show="open" x-cloak class="mt-4 grid gap-4 border-t border-line pt-4 sm:grid-cols-2">
                    {{-- تعديل --}}
                    <form method="POST" action="{{ route('admin.attributes.update', $attribute) }}" class="space-y-2">
                        @csrf
                        <h3 class="text-xs font-semibold">تعديل</h3>
                        <input name="name_ar" value="{{ $attribute->name_ar }}" required maxlength="120"
                               class="w-full rounded-lg border border-line px-2.5 py-1.5 text-sm">
                        <input name="unit" value="{{ $attribute->unit }}" placeholder="الوحدة"
                               class="w-full rounded-lg border border-line px-2.5 py-1.5 text-sm">
                        @if ($attribute->data_type === 'number')
                            <div class="grid grid-cols-2 gap-2">
                                <input name="min_value" type="number" step="any" placeholder="أدنى"
                                       value="{{ $attribute->min_value }}"
                                       class="rounded-lg border border-line px-2.5 py-1.5 text-sm">
                                <input name="max_value" type="number" step="any" placeholder="أعلى"
                                       value="{{ $attribute->max_value }}"
                                       class="rounded-lg border border-line px-2.5 py-1.5 text-sm">
                            </div>
                        @endif
                        <button class="rounded-lg bg-ink px-3 py-1.5 text-xs font-semibold text-white">حفظ</button>
                        <p class="text-xs text-muted">
                            المفتاح والنوع لا يُعدَّلان: المفتاح يظهر في روابط بحث محفوظة، والنوع يحدّد أي عمود يحمل القيم.
                        </p>
                    </form>

                    {{-- الخيارات --}}
                    @if ($attribute->data_type === 'option')
                        <div class="space-y-2">
                            <h3 class="text-xs font-semibold">الخيارات</h3>
                            <ul class="flex flex-wrap gap-1.5">
                                @forelse ($attribute->options as $option)
                                    <li class="rounded-md bg-tint px-2 py-0.5 text-xs">{{ $option->value_ar }}</li>
                                @empty
                                    <li class="text-xs text-muted">لا خيارات بعد — الخاصية بلا خيارات لا تُعرض في النموذج.</li>
                                @endforelse
                            </ul>
                            <form method="POST" action="{{ route('admin.attributes.options', $attribute) }}"
                                  class="flex gap-2">
                                @csrf
                                <input name="value_ar" required maxlength="120" placeholder="خيار جديد"
                                       class="w-full rounded-lg border border-line px-2.5 py-1.5 text-sm">
                                <button class="shrink-0 rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                    أضف
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- ═══ خاصية جديدة ═══ --}}
    <form method="POST" action="{{ route('admin.attributes.store') }}"
          class="h-fit space-y-3 rounded-2xl border border-line bg-white p-5">
        @csrf
        <h2 class="text-sm font-semibold">خاصية جديدة</h2>

        <div>
            <label for="attr-key" class="block text-xs font-medium">المفتاح <span class="text-red-600">*</span></label>
            <input id="attr-key" name="attr_key" value="{{ old('attr_key') }}" required dir="ltr" placeholder="mileage"
                   class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-muted">يظهر في رابط البحث — لا يُعدَّل بعد الإنشاء.</p>
        </div>

        <div>
            <label for="attr-name" class="block text-xs font-medium">الاسم <span class="text-red-600">*</span></label>
            <input id="attr-name" name="name_ar" value="{{ old('name_ar') }}" required maxlength="120"
                   class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
        </div>

        <div>
            <label for="attr-type" class="block text-xs font-medium">النوع <span class="text-red-600">*</span></label>
            <select id="attr-type" name="data_type" required
                    class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('data_type') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-muted">الرقم وحده يقبل الفلترة بنطاق. النوع لا يُعدَّل بعد الإنشاء.</p>
        </div>

        <div class="grid grid-cols-3 gap-2">
            <div>
                <label for="attr-unit" class="block text-xs font-medium">الوحدة</label>
                <input id="attr-unit" name="unit" value="{{ old('unit') }}" maxlength="20" placeholder="كم"
                       class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
            </div>
            <div>
                <label for="attr-min" class="block text-xs font-medium">أدنى</label>
                <input id="attr-min" name="min_value" type="number" step="any" value="{{ old('min_value') }}"
                       class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
            </div>
            <div>
                <label for="attr-max" class="block text-xs font-medium">أعلى</label>
                <input id="attr-max" name="max_value" type="number" step="any" value="{{ old('max_value') }}"
                       class="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm">
            </div>
        </div>

        <button class="w-full rounded-xl bg-ink px-4 py-2.5 text-sm font-semibold text-white">أنشئ الخاصية</button>
    </form>
</div>

{{-- ═══ الربط بالتصنيفات ═══ --}}
<h2 class="mt-8 text-base font-semibold">ربط الخصائص بالتصنيفات</h2>
<p class="mt-1 text-sm text-muted">
    الفرعية وحدها تُربط — الخاصية تخصّ سلعة بعينها لا قسماً كاملاً. وإزالة العلامة تفكّ الربط ولا تمسّ القيم المحفوظة.
</p>

<div class="mt-4 grid gap-3 md:grid-cols-2">
    @foreach ($categories as $category)
        @php($linked = $category->attributes->pluck('id')->all())

        <form method="POST" action="{{ route('admin.categories.attributes', $category) }}"
              class="rounded-2xl border border-line bg-white p-4">
            @csrf
            <div class="flex items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium">
                    {{ $category->name_ar }}
                    <span class="text-xs font-normal text-muted">{{ $category->parent?->name_ar }}</span>
                </h3>
                <button class="rounded-lg border border-line px-2.5 py-1 text-xs font-medium transition hover:bg-tint">
                    حفظ
                </button>
            </div>

            <div class="mt-3 grid gap-1.5">
                @foreach ($attributes as $attribute)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="attributes[]" value="{{ $attribute->id }}"
                               @checked(in_array($attribute->id, $linked, true))
                               class="size-4 rounded border-line">
                        <span>{{ $attribute->name_ar }}</span>
                        <span class="text-xs text-muted" dir="ltr">{{ $attribute->attr_key }}</span>
                    </label>
                @endforeach
            </div>
        </form>
    @endforeach
</div>

@endsection
