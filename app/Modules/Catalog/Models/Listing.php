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

    /**
     * الأعمدة المحسوبة (title_normalized, description_normalized,
     * price_in_base, rate_used, rate_date) غائبة عن هذه القائمة بقصد —
     * غيابها وحده يمنع تمريرها من طلب HTTP، لأن Eloquent يتجاهل $guarded
     * كلياً متى كان $fillable غير فارغ. لا تُضِف $guarded هنا: يوهم بحماية
     * ثانية غير موجودة.
     *
     * وبنفس المنطق: renewed_at وrenewals_count غائبان. التجديد حدث
     * يقرّره الخادم بشروطه، لا حقل في نموذج يملأه المعلن.
     */
    protected $fillable = [
        'user_id', 'category_id', 'location_id',
        'title', 'slug', 'description',
        'price_amount', 'currency_code', 'price_type', 'is_negotiable',
        'sale_type', 'condition_type', 'status',
        'contact_phone', 'hide_phone',
        'is_featured', 'featured_until', 'published_at', 'expires_at',
    ];

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
            'sold_at' => 'datetime',
            'renewed_at' => 'datetime',
            'renewals_count' => 'integer',
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

    /**
     * الانتهاء زمني لا حالة.
     *
     * لماذا هنا وليس بمهمة مجدولة تُحوّل status إلى expired: المهمة قد تتأخر
     * أو تتوقف، وحينها تظهر إعلانات منتهية في النتائج — والمستخدم يتصل برقم
     * لإعلان لم يعد قائماً. الشرط الزمني صحيح دائماً بلا اعتماد على مجدول.
     * المهمة المجدولة تبقى مفيدة للتنظيف، لا كخط الدفاع الأول.
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
        });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->active()->whereNotNull('published_at')->notExpired();
    }

    /** إعلانات مالك واحد — نقطة الدخول الوحيدة لكل استعلامات اللوحة */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * المميّزة أولاً — بشرط أن تكون ترقيتها سارية.
     *
     * is_featured وحده لا يكفي: featured_until موجود في الجدول ولم يكن
     * يُفحص، فإعلان انتهت ترقيته يبقى مثبّتاً في أعلى النتائج إلى الأبد.
     */
    public function scopeFeaturedFirst(Builder $query): Builder
    {
        return $query->orderByRaw(
            '(is_featured = 1 and (featured_until is null or featured_until >= ?)) desc',
            [now()]
        );
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

    public function isFeaturedNow(): bool
    {
        return $this->is_featured
            && ($this->featured_until === null || $this->featured_until->isFuture());
    }

    /**
     * الأيام المتبقية — سالبة للمنتهي، وnull لإعلان بلا تاريخ انتهاء.
     *
     * الحساب من بداية اليوم لا من اللحظة: «يتبقى يوم واحد» أوضح
     * للمعلن من «يتبقى 0 أيام» لإعلان ينتهي بعد ثلاث ساعات.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        // diffInDays يُرجع float في Carbon 3 — التقريب صريح لا ضمني
        return (int) round(now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false));
    }

    /** حالة معروضة للمعلن — تدمج الحالة المخزّنة مع الانتهاء الزمني */
    public function ownerStatusLabel(): string
    {
        if ($this->trashed()) {
            return 'محذوف';
        }

        if ($this->isExpired()) {
            return 'منتهي';
        }

        return match ($this->status) {
            'active' => 'منشور',
            'paused' => 'موقوف',
            'sold' => 'تم البيع',
            'draft' => 'مسودة',
            'pending' => 'بانتظار المراجعة',
            'rejected' => 'مرفوض',
            default => $this->status,
        };
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
