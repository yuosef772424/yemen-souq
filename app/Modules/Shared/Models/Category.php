<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $table = 'categories';

    protected $fillable = [
        'parent_id', 'name_ar', 'name_normalized', 'slug', 'icon',
        'path', 'depth', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // الاسم المطبّع والمسار يُحسبان تلقائياً — لا يُمرَّران يدوياً
        static::saving(function (self $category): void {
            $category->name_normalized = app(ArabicNormalizer::class)->normalize($category->name_ar);

            $parent = $category->parent_id ? static::find($category->parent_id) : null;
            $category->depth = $parent ? $parent->depth + 1 : 0;
        });

        static::saved(function (self $category): void {
            $parent = $category->parent_id ? static::find($category->parent_id) : null;
            $path = $parent ? $parent->path.'/'.$category->id : (string) $category->id;

            if ($category->path !== $path) {
                static::withoutEvents(fn () => $category->newQuery()
                    ->whereKey($category->getKey())
                    ->update(['path' => $path]));
                $category->path = $path;
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attributes')
            ->withPivot(['is_required', 'is_filterable', 'sort_order'])
            ->orderByPivot('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id')->orderBy('sort_order');
    }

    /** كل التصنيفات تحت هذا التصنيف، بما فيه هو — بلا استعلامات متكررة */
    public function scopeInSubtree(Builder $query, string $path): Builder
    {
        return $query->where(function (Builder $q) use ($path): void {
            $q->where('path', $path)->orWhere('path', 'like', $path.'/%');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
