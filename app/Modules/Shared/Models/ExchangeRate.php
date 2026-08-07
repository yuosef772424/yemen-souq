<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    public $timestamps = false;

    protected $fillable = [
        'currency_code', 'rate_to_base', 'effective_date', 'source', 'note',
    ];

    protected function casts(): array
    {
        return [
            'rate_to_base' => 'decimal:6',
            'effective_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * السعر الساري في تاريخ معيّن: آخر سعر مسجّل في ذلك التاريخ أو قبله.
     * هذا ما يجعل التسعير التاريخي صحيحاً بدل استخدام آخر سعر دائماً.
     */
    public function scopeEffectiveOn(Builder $query, string $currencyCode, ?string $date = null): Builder
    {
        return $query
            ->where('currency_code', $currencyCode)
            ->whereDate('effective_date', '<=', $date ?? now()->toDateString())
            ->orderByDesc('effective_date')
            ->limit(1);
    }
}
