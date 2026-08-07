<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingPriceHistory extends Model
{
    protected $table = 'listing_price_history';

    public $timestamps = false;

    protected $fillable = [
        'listing_id',
        'old_amount', 'old_currency',
        'new_amount', 'new_currency', 'new_in_base',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_amount' => 'decimal:2',
            'new_amount' => 'decimal:2',
            'new_in_base' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
