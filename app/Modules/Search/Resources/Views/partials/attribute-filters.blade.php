{{--
    فلاتر خصائص التصنيف المختار.

    لا تظهر إلا بعد اختيار تصنيف، وهذا ليس تقييداً بل معنى: «عدد الغرف»
    بلا تصنيف يعني فلترة السوق كله بخاصية لا يملكها إلا العقار.

    ولا JavaScript هنا بخلاف نموذج النشر: هذا نموذج GET يعيد تحميل
    الصفحة، فالتصنيف المختار معروف في الخادم وقت العرض — تُبنى حقوله
    وحدها ولا حاجة إلى إخفاء غيرها.

    والمفتاح في اسم الحقل هو attr_key لا المعرّف: attr[mileage][max]
    يبقى مقروءاً في رابط منسوخ أو مُشارَك.

    @param  \Illuminate\Support\Collection  $categoryAttributes
    @param  \App\Modules\Search\DTO\SearchCriteria  $criteria
--}}
@php($applied = collect($criteria->attributes)->keyBy('key'))

@if ($categoryAttributes->isNotEmpty())
    <fieldset class="rounded-2xl border border-line bg-white p-4">
        <legend class="px-1 text-sm font-semibold">مواصفات التصنيف</legend>

        @foreach ($categoryAttributes as $attribute)
            @php($filter = $applied->get($attribute->attr_key))

            <div class="mt-3 first:mt-2">
                <span class="block text-xs font-medium text-muted">
                    {{ $attribute->name_ar }}
                    @if ($attribute->unit)
                        ({{ $attribute->unit }})
                    @endif
                </span>

                @if ($attribute->data_type === 'number')
                    <div class="mt-1.5 grid grid-cols-2 gap-2">
                        <input type="number" step="any" inputmode="decimal" placeholder="من"
                               name="attr[{{ $attribute->attr_key }}][min]"
                               value="{{ $filter?->min }}"
                               class="w-full rounded-lg border border-line px-3 py-2 text-sm tabular-nums focus:border-brand focus:outline-none">
                        <input type="number" step="any" inputmode="decimal" placeholder="إلى"
                               name="attr[{{ $attribute->attr_key }}][max]"
                               value="{{ $filter?->max }}"
                               class="w-full rounded-lg border border-line px-3 py-2 text-sm tabular-nums focus:border-brand focus:outline-none">
                    </div>

                @elseif ($attribute->data_type === 'option')
                    <select name="attr[{{ $attribute->attr_key }}]"
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
                        <option value="">الكل</option>
                        @foreach ($attribute->options as $option)
                            <option value="{{ $option->id }}" @selected($filter?->equals === $option->id)>
                                {{ $option->value_ar }}
                            </option>
                        @endforeach
                    </select>

                @elseif ($attribute->data_type === 'multi_option')
                    {{-- مربّعات لا قائمة: الدلالة «أيّ منها»، والقائمة المنسدلة تُخفي أن الاختيار متعدد --}}
                    <div class="mt-1.5 grid gap-1 sm:grid-cols-2">
                        @foreach ($attribute->options as $option)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="attr[{{ $attribute->attr_key }}][]"
                                       value="{{ $option->id }}"
                                       @checked(in_array($option->id, $filter?->anyOf ?? [], true))
                                       class="size-4 rounded border-line text-brand focus:ring-brand">
                                <span>{{ $option->value_ar }}</span>
                            </label>
                        @endforeach
                    </div>

                @elseif ($attribute->data_type === 'boolean')
                    <select name="attr[{{ $attribute->attr_key }}]"
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-brand focus:outline-none">
                        <option value="">الكل</option>
                        <option value="1" @selected($filter?->equals === 1)>نعم</option>
                        <option value="0" @selected($filter?->equals === 0)>لا</option>
                    </select>
                @endif
            </div>
        @endforeach
    </fieldset>
@endif
