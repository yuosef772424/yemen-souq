<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Currency extends Model
{
    protected $table = 'currencies';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'symbol',
        'decimals', 'is_base', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'is_base' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'currency_code', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /** عملة الأساس التي تُحسب إليها كل الأسعار للمقارنة */
    public static function base(): self
    {
        return static::where('is_base', true)->firstOrFail();
    }

    /** تنسيق مبلغ بهذه العملة للعرض */
    public function format(float|string|null $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format((float) $amount, $this->decimals) . ' ' . $this->symbol;
    }
}
