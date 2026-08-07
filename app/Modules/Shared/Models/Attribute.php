<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    protected $table = 'attributes';

    protected $fillable = [
        'attr_key', 'name_ar', 'data_type', 'unit', 'min_value', 'max_value',
    ];

    protected function casts(): array
    {
        return [
            'min_value' => 'decimal:4',
            'max_value' => 'decimal:4',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class)->orderBy('sort_order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attributes')
            ->withPivot(['is_required', 'is_filterable', 'sort_order']);
    }

    /** هل تقبل هذه الصفة أكثر من قيمة واحدة؟ */
    public function isMulti(): bool
    {
        return $this->data_type === 'multi_option';
    }

    /** أي عمود في listing_attribute_values يحمل القيمة لهذا النوع */
    public function valueColumn(): string
    {
        return match ($this->data_type) {
            'number' => 'value_number',
            'boolean' => 'value_boolean',
            'date' => 'value_date',
            'option' => 'option_id',
            default => 'value_string',
        };
    }
}
