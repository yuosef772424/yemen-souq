<?php

namespace Tests\Feature\Shared;

use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExchangeRateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ExchangeRateService::class);

        Currency::create([
            'code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'Yemeni Rial',
            'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true,
        ]);

        Currency::create([
            'code' => 'USD', 'name_ar' => 'دولار', 'name_en' => 'US Dollar',
            'symbol' => '$', 'decimals' => 2, 'is_base' => false,
        ]);

        Currency::create([
            'code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal',
            'symbol' => 'ر.س', 'decimals' => 2, 'is_base' => false,
        ]);

        $this->rate('YER', 1, '2026-01-01');
        $this->rate('USD', 500, '2026-01-01');
        $this->rate('SAR', 133, '2026-01-01');
    }

    private function rate(string $code, float $value, string $date): void
    {
        ExchangeRate::create([
            'currency_code' => $code,
            'rate_to_base' => $value,
            'effective_date' => $date,
            'source' => 'test',
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function it_converts_a_foreign_amount_to_base(): void
    {
        $result = $this->service->toBase(100, 'USD', '2026-01-01');

        $this->assertSame(50000.0, $result['amount']);
        $this->assertSame(500.0, $result['rate']);
    }

    #[Test]
    public function base_currency_conversion_is_identity(): void
    {
        $result = $this->service->toBase(2500, 'YER', '2026-01-01');

        $this->assertSame(2500.0, $result['amount']);
    }

    #[Test]
    public function it_converts_from_base_back_to_a_currency(): void
    {
        $this->assertSame(100.0, $this->service->fromBase(50000, 'USD', '2026-01-01'));
    }

    #[Test]
    public function it_converts_directly_between_two_foreign_currencies(): void
    {
        // 133 ريالاً سعودياً = 17689 ريالاً يمنياً = 35.38 دولاراً
        $this->assertSame(35.38, $this->service->convert(133, 'SAR', 'USD', '2026-01-01'));
    }

    #[Test]
    public function converting_to_the_same_currency_changes_nothing(): void
    {
        $this->assertSame(75.0, $this->service->convert(75, 'USD', 'USD', '2026-01-01'));
    }

    #[Test]
    public function it_uses_the_rate_effective_on_that_date_not_the_latest(): void
    {
        $this->rate('USD', 530, '2026-06-01');

        // تاريخ قديم يستخدم السعر القديم
        $this->assertSame(50000.0, $this->service->toBase(100, 'USD', '2026-03-01')['amount']);

        // تاريخ لاحق يستخدم السعر الجديد
        $this->assertSame(53000.0, $this->service->toBase(100, 'USD', '2026-07-01')['amount']);
    }

    #[Test]
    public function it_converts_a_whole_price_range(): void
    {
        $range = $this->service->convertRange(10, 200, 'USD', '2026-01-01');

        $this->assertSame(5000.0, $range['min']);
        $this->assertSame(100000.0, $range['max']);
    }

    #[Test]
    public function an_open_ended_range_keeps_its_null_bound(): void
    {
        $range = $this->service->convertRange(null, 50, 'USD', '2026-01-01');

        $this->assertNull($range['min']);
        $this->assertSame(25000.0, $range['max']);
    }

    #[Test]
    public function it_fails_loudly_when_no_rate_exists_for_the_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->toBase(100, 'USD', '2025-01-01');
    }

    #[Test]
    public function equal_numbers_in_different_currencies_are_not_equal_in_value(): void
    {
        // جوهر المشكلة التي يحلها المشروع
        $inUsd = $this->service->toBase(100, 'USD', '2026-01-01')['amount'];
        $inYer = $this->service->toBase(100, 'YER', '2026-01-01')['amount'];

        $this->assertNotSame($inUsd, $inYer);
        $this->assertGreaterThan($inYer, $inUsd);
    }
}
