<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $table = 'locations';

    protected $fillable = [
        'parent_id', 'name_ar', 'name_normalized', 'slug', 'level',
        'path', 'depth', 'latitude', 'longitude', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $location): void {
            $location->name_normalized = app(ArabicNormalizer::class)->normalize($location->name_ar);

            $parent = $location->parent_id ? static::find($location->parent_id) : null;
            $location->depth = $parent ? $parent->depth + 1 : 0;
        });

        static::saved(function (self $location): void {
            $parent = $location->parent_id ? static::find($location->parent_id) : null;
            $path = $parent ? $parent->path . '/' . $location->id : (string) $location->id;

            if ($location->path !== $path) {
                static::withoutEvents(fn () => $location->newQuery()
                    ->whereKey($location->getKey())
                    ->update(['path' => $path]));
                $location->path = $path;
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeGovernorates(Builder $query): Builder
    {
        return $query->where('level', 'governorate')->orderBy('sort_order');
    }

    public function scopeInSubtree(Builder $query, string $path): Builder
    {
        return $query->where(function (Builder $q) use ($path): void {
            $q->where('path', $path)->orWhere('path', 'like', $path . '/%');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
