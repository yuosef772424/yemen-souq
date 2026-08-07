<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeOption extends Model
{
    protected $table = 'attribute_options';

    public $timestamps = false;

    protected $fillable = [
        'attribute_id', 'value_ar', 'value_normalized', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $option): void {
            $option->value_normalized = app(ArabicNormalizer::class)->normalize($option->value_ar);
        });
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
