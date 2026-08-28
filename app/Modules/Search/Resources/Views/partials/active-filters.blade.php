{{--
    شرائح الفلاتر النشطة.

    بعد تطبيق فلتر من اللوحة الجانبية (المطوية على الجوال والمنزوية على سطح
    المكتب) لا يرى المستخدم أثره إلا بفتح اللوحة من جديد. هذا الصفّ يُظهر كل
    فلتر مُفعَّل كشريحة قابلة للإزالة بنقرة واحدة — رابط GET عادي بلا JS، يعيد
    تحميل البحث بنفس المعاملات باستثناء معامل ذلك الفلتر وحده.

    @param  \App\Modules\Search\DTO\SearchCriteria  $criteria
    @param  \Illuminate\Support\Collection  $categories
    @param  \Illuminate\Support\Collection  $governorates
    @param  \Illuminate\Support\Collection  $currencies
    @param  \Illuminate\Support\Collection  $categoryAttributes
--}}
@php
    $conditionLabels = ['new' => 'جديد', 'like_new' => 'كالجديد', 'used' => 'مستعمل', 'for_parts' => 'قطع غيار'];

    $chips = [];

    if ($criteria->categoryId !== null) {
        $categoryName = $categories
            ->flatMap(fn ($category) => [$category, ...$category->children])
            ->firstWhere('id', $criteria->categoryId)
            ?->name_ar;

        if ($categoryName !== null) {
            // المواصفات تابعة للتصنيف: إزالته بلا إزالتها يُبقي فلتراً معلَّقاً بلا معنى
            $chips[] = ['label' => $categoryName, 'except' => ['category', 'attr']];
        }
    }

    if ($criteria->locationId !== null) {
        $locationName = $governorates->firstWhere('id', $criteria->locationId)?->name_ar;

        if ($locationName !== null) {
            $chips[] = ['label' => $locationName, 'except' => ['location']];
        }
    }

    if ($criteria->hasPriceFilter()) {
        $symbol = $currencies->firstWhere('code', $criteria->priceCurrency)?->symbol ?? $criteria->priceCurrency;

        $priceLabel = match (true) {
            $criteria->priceMin !== null && $criteria->priceMax !== null =>
                'السعر: ' . number_format($criteria->priceMin) . ' – ' . number_format($criteria->priceMax) . ' ' . $symbol,
            $criteria->priceMin !== null => 'السعر: من ' . number_format($criteria->priceMin) . ' ' . $symbol,
            default => 'السعر: حتى ' . number_format($criteria->priceMax) . ' ' . $symbol,
        };

        $chips[] = ['label' => $priceLabel, 'except' => ['min', 'max', 'currency']];
    }

    if ($criteria->listingCurrency !== null) {
        $currencyName = $currencies->firstWhere('code', $criteria->listingCurrency)?->name_ar;

        if ($currencyName !== null) {
            $chips[] = ['label' => 'عملة الإعلان: ' . $currencyName, 'except' => ['listing_currency']];
        }
    }

    if ($criteria->condition !== null) {
        $chips[] = ['label' => $conditionLabels[$criteria->condition] ?? $criteria->condition, 'except' => ['condition']];
    }

    if ($criteria->withImageOnly) {
        $chips[] = ['label' => 'بصور فقط', 'except' => ['with_image']];
    }

    foreach ($criteria->attributes as $attribute) {
        $name = $categoryAttributes->firstWhere('attr_key', $attribute->key)?->name_ar ?? $attribute->key;
        $chips[] = ['label' => $name, 'except' => ["attr.{$attribute->key}"]];
    }
@endphp

@if ($chips !== [])
    <div class="mt-3 flex flex-wrap items-center gap-2">
        @foreach ($chips as $chip)
            <a href="{{ route('search', request()->except($chip['except'])) }}"
               class="inline-flex items-center gap-1.5 rounded-full bg-tint px-3 py-1.5 text-xs font-medium text-ink ring-1 ring-line transition hover:bg-line/70">
                {{ $chip['label'] }}
                <span aria-hidden="true" class="text-muted">×</span>
                <span class="sr-only">إزالة</span>
            </a>
        @endforeach

        <a href="{{ route('search') }}" class="text-xs font-medium text-muted underline hover:text-ink">
            مسح الكل
        </a>
    </div>
@endif
