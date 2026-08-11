{{--
    حقول المواصفات الخاصة بالتصنيف.

    تُعرض حقول كل التصنيفات وتُخفى غير المعنيّة بـAlpine بدل جلبها بطلب
    عند تغيير التصنيف: الثلاثون ارتباطاً كلها بضعة كيلوبايتات، ورحلة
    شبكة لكل تغيير تصنيف أبطأ وأهشّ — تفشل على اتصال ضعيف فيبدو النموذج
    معطَّلاً.

    والإخفاء بالعرض لا يمنع الإرسال: الحقول المخفية تُرسَل مع النموذج.
    الحارس هو ListingAttributes::write الذي يُقصي كل صفة لا يملكها
    التصنيف المختار.

    @param  \Illuminate\Support\Collection  $attributesByCategory
    @param  array                           $selected  القيم الحالية: attribute_id => value
--}}
@php($selected = $selected ?? [])

<fieldset x-show="hasAttributes" x-cloak class="rounded-2xl border border-line bg-white p-5">
    <legend class="px-1 text-sm font-semibold">مواصفات التصنيف</legend>

    <p class="mt-1 text-xs text-muted">
        كل حقل اختياري — لكنّ الإعلان الذي يذكرها يظهر في نتائج الفلترة.
    </p>

    @foreach ($attributesByCategory as $categoryId => $attributes)
        <div x-show="categoryId == '{{ $categoryId }}'" x-cloak class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach ($attributes as $attribute)
                @php($field = 'attributes.'.$attribute->id)
                @php($current = old($field, $selected[$attribute->id] ?? null))

                <div>
                    <label for="attr-{{ $categoryId }}-{{ $attribute->id }}" class="block text-sm font-medium">
                        {{ $attribute->name_ar }}
                        @if ($attribute->unit)
                            <span class="text-xs font-normal text-muted">({{ $attribute->unit }})</span>
                        @endif
                        @if ($attribute->pivot->is_required)
                            <span class="text-red-600">*</span>
                        @endif
                    </label>

                    @if ($attribute->data_type === 'option')
                        <select id="attr-{{ $categoryId }}-{{ $attribute->id }}"
                                name="attributes[{{ $attribute->id }}]"
                                class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                            <option value="">اختر…</option>
                            @foreach ($attribute->options as $option)
                                <option value="{{ $option->id }}" @selected((string) $current === (string) $option->id)>
                                    {{ $option->value_ar }}
                                </option>
                            @endforeach
                        </select>

                    @elseif ($attribute->data_type === 'multi_option')
                        @php($chosen = array_map('strval', (array) old($field, $selected[$attribute->id] ?? [])))
                        <div class="mt-1.5 grid gap-1.5 sm:grid-cols-2">
                            @foreach ($attribute->options as $option)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="attributes[{{ $attribute->id }}][]"
                                           value="{{ $option->id }}"
                                           @checked(in_array((string) $option->id, $chosen, true))
                                           class="size-4 rounded border-line text-brand focus:ring-brand">
                                    <span>{{ $option->value_ar }}</span>
                                </label>
                            @endforeach
                        </div>

                    @elseif ($attribute->data_type === 'boolean')
                        <label class="mt-1.5 flex items-center gap-2 text-sm">
                            <input id="attr-{{ $categoryId }}-{{ $attribute->id }}"
                                   type="checkbox" name="attributes[{{ $attribute->id }}]" value="1"
                                   @checked((bool) $current)
                                   class="size-4 rounded border-line text-brand focus:ring-brand">
                            <span class="text-muted">نعم</span>
                        </label>

                    @else
                        <input id="attr-{{ $categoryId }}-{{ $attribute->id }}"
                               name="attributes[{{ $attribute->id }}]"
                               @class(['mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none'])
                               @if ($attribute->data_type === 'number')
                                   type="number" step="any" inputmode="decimal"
                                   @if ($attribute->min_value !== null) min="{{ $attribute->min_value }}" @endif
                                   @if ($attribute->max_value !== null) max="{{ $attribute->max_value }}" @endif
                               @elseif ($attribute->data_type === 'date')
                                   type="date"
                               @else
                                   type="text" maxlength="255"
                               @endif
                               value="{{ $current }}">
                    @endif

                    @error($field)
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>
    @endforeach
</fieldset>
