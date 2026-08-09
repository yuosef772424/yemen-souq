<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class Currency extends Model
{
    protected $table = 'currencies';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    private const CODES_CACHE_KEY = 'currencies:active-codes';
    private const BASE_CACHE_KEY = 'currencies:base-code';
    private const CACHE_TTL = 3600;

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

    /**
     * رموز العملات النشطة — قائمة بيضاء للتحقق من مُدخلات المستخدم.
     *
     * لماذا مخزَّنة مؤقتاً: تُستدعى في كل طلب بحث، وجدول العملات لا يتغيّر
     * أكثر من مرات في العمر. البديل المرفوض: ثابت في الكود — يجعل إضافة
     * عملة رابعة تعديلاً في مكانين، وأحدهما سيُنسى.
     */
    public static function activeCodes(): array
    {
        return Cache::remember(
            self::CODES_CACHE_KEY,
            self::CACHE_TTL,
            fn (): array => static::active()->pluck('code')->all()
        );
    }

    /** رمز عملة الأساس بلا تحميل الموديل — يُستخدم كقيمة افتراضية آمنة */
    public static function baseCode(): string
    {
        return Cache::remember(
            self::BASE_CACHE_KEY,
            self::CACHE_TTL,
            fn (): string => (string) (static::where('is_base', true)->value('code') ?? 'YER')
        );
    }

    /** يُستدعى بعد أي تعديل على جدول العملات */
    public static function flushCache(): void
    {
        Cache::forget(self::CODES_CACHE_KEY);
        Cache::forget(self::BASE_CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
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