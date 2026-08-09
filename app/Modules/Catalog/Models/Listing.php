<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Modules\Catalog\Database\Factories\ListingFactory;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'listings';

    protected $fillable = [
        'user_id', 'category_id', 'location_id',
        'title', 'slug', 'description',
        'price_amount', 'currency_code', 'price_type', 'is_negotiable',
        'sale_type', 'condition_type', 'status',
        'contact_phone', 'hide_phone',
        'is_featured', 'featured_until', 'published_at', 'expires_at',
    ];

    /**
     * الأعمدة المحسوبة — title_normalized و description_normalized
     * و price_in_base و rate_used و rate_date — مستثناة من $fillable
     * عن قصد. يملؤها ListingObserver وحده، فلا يستطيع طلب HTTP
     * تمرير قيمة مزيّفة لها.
     *
     * لا نستخدم $guarded: لارافيل يتجاهله تماماً حين يكون $fillable
     * غير فارغ، فوجوده معاً يوحي بحماية مزدوجة غير قائمة.
     */
    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'price_in_base' => 'decimal:2',
            'rate_used' => 'decimal:6',
            'rate_date' => 'date',
            'is_negotiable' => 'boolean',
            'hide_phone' => 'boolean',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // ---- العلاقات ----

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ListingImage::class)->orderBy('sort_order');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ListingPriceHistory::class)->latest('changed_at');
    }

    // ---- النطاقات ----

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->active()->whereNotNull('published_at');
    }

    /** الفلترة بالسعر تجري دائماً على العمود الموحّد، لا على السعر الأصلي */
    public function scopePriceBetween(Builder $query, ?float $minInBase, ?float $maxInBase): Builder
    {
        return $query
            ->when($minInBase !== null, fn (Builder $q) => $q->where('price_in_base', '>=', $minInBase))
            ->when($maxInBase !== null, fn (Builder $q) => $q->where('price_in_base', '<=', $maxInBase));
    }

    public function scopeInCategory(Builder $query, Category $category): Builder
    {
        return $query->whereIn(
            'category_id',
            Category::inSubtree($category->path)->select('id')
        );
    }

    public function scopeInLocation(Builder $query, Location $location): Builder
    {
        return $query->whereIn(
            'location_id',
            Location::inSubtree($location->path)->select('id')
        );
    }

    // ---- مساعدات العرض ----

    /** السعر بعملته الأصلية كما أدخله المعلن — هذا ما يُعرض دائماً */
    public function formattedPrice(): string
    {
        if ($this->price_type === 'free') {
            return 'مجاناً';
        }

        if ($this->price_amount === null) {
            return 'السعر عند الطلب';
        }

        return $this->currency?->format($this->price_amount) ?? (string) $this->price_amount;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function newFactory(): ListingFactory
    {
        return ListingFactory::new();
    }
}
