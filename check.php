<?php

use App\Modules\Catalog\Models\Listing;

// الحالات المقصودة: نفس الرقم، عملات مختلفة
dump(
    Listing::whereIn('price_amount', [100, 50000])
        ->orderBy('price_amount')
        ->get(['title', 'price_amount', 'currency_code', 'price_in_base'])
        ->map(fn ($l) => "{$l->price_amount} {$l->currency_code} = {$l->price_in_base} ر.ي")
        ->all()
);

dump([
    'total' => Listing::count(),
    'by_currency' => Listing::selectRaw('currency_code, count(*) as n')
        ->groupBy('currency_code')->pluck('n', 'currency_code'),
    'between_20k_60k_base' => Listing::priceBetween(20000, 60000)->count(),
]);