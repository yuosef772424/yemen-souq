<?php

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * تحويل المبالغ إلى عملة الأساس لأغراض المقارنة والفلترة.
 *
 * المشكلة التي يحلها: إعلان بـ100 دولار وإعلان بـ100 ريال يمني
 * يحملان نفس الرقم لكن قيمتهما مختلفة تماماً. الفلترة بالسعر
 * دون توحيد العملة تعطي نتائج مضللة.
 *
 * القاعدة: السعر الأصلي وعملته يُحفظان كما أدخلهما المعلن ويُعرضان كما هما.
 * القيمة المحوّلة تُخزَّن في عمود منفصل وتُستخدم للفلترة والترتيب فقط.
 */
final class ExchangeRateService
{
    private const CACHE_TTL = 3600;

    /**
     * سعر الصرف الساري لعملة في تاريخ معيّن.
     * يرجع آخر سعر مسجّل في ذلك التاريخ أو قبله — لا أحدث سعر مطلقاً.
     */
    public function rateFor(string $currencyCode, ?string $date = null): float
    {
        $date ??= now()->toDateString();

        $key = "rate:{$currencyCode}:{$date}";

        return Cache::remember($key, self::CACHE_TTL, function () use ($currencyCode, $date): float {
            $rate = ExchangeRate::effectiveOn($currencyCode, $date)->first();

            if ($rate === null) {
                throw new InvalidArgumentException(
                    "لا يوجد سعر صرف مسجّل للعملة {$currencyCode} بتاريخ {$date} أو قبله."
                );
            }

            return (float) $rate->rate_to_base;
        });
    }

    /**
     * تحويل مبلغ من عملته إلى عملة الأساس.
     *
     * @return array{amount: float, rate: float, date: string}
     */
    public function toBase(float $amount, string $currencyCode, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $rate = $this->rateFor($currencyCode, $date);

        return [
            'amount' => round($amount * $rate, 2),
            'rate' => $rate,
            'date' => $date,
        ];
    }

    /**
     * تحويل مبلغ من عملة الأساس إلى عملة أخرى.
     * يُستخدم عند الفلترة: المستخدم يحدد نطاقاً بعملته، فنحوّل حدوده.
     */
    public function fromBase(float $baseAmount, string $currencyCode, ?string $date = null): float
    {
        $rate = $this->rateFor($currencyCode, $date);

        if ($rate <= 0) {
            throw new InvalidArgumentException("سعر صرف غير صالح للعملة {$currencyCode}.");
        }

        return round($baseAmount / $rate, 2);
    }

    /**
     * تحويل مباشر بين عملتين عبر عملة الأساس.
     */
    public function convert(float $amount, string $from, string $to, ?string $date = null): float
    {
        if ($from === $to) {
            return round($amount, 2);
        }

        $inBase = $this->toBase($amount, $from, $date)['amount'];

        return $this->fromBase($inBase, $to, $date);
    }

    /**
     * تحويل نطاق سعري كامل إلى عملة الأساس — هذا ما يستدعيه محرك البحث.
     *
     * @return array{min: ?float, max: ?float}
     */
    public function convertRange(?float $min, ?float $max, string $currencyCode, ?string $date = null): array
    {
        $rate = $this->rateFor($currencyCode, $date);

        return [
            'min' => $min !== null ? round($min * $rate, 2) : null,
            'max' => $max !== null ? round($max * $rate, 2) : null,
        ];
    }

    /** كل الأسعار السارية اليوم — للعرض في لوحة الإدارة */
    public function currentRates(?string $date = null): array
    {
        return Currency::active()
            ->get()
            ->mapWithKeys(fn (Currency $c): array => [
                $c->code => $this->rateFor($c->code, $date),
            ])
            ->all();
    }

    /** يُستدعى بعد تحديث الأسعار حتى لا تبقى القيم القديمة مخزّنة */
    public function flushCache(): void
    {
        foreach (Currency::pluck('code') as $code) {
            Cache::forget("rate:{$code}:".now()->toDateString());
        }
    }
}
