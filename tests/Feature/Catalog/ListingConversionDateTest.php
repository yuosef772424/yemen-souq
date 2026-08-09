<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * العيب: تعديل السعر كان يُحوَّل بسعر صرف تاريخ الإنشاء لا تاريخ التعديل.
 */
class ListingConversionDateTest extends TestCase
{
    use RefreshDatabase;

    private string $oldDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldDate = now()->subMonths(3)->toDateString();

        Currency::query()->delete();
        Currency::create(['code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'Yemeni Rial', 'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true, 'is_active' => true, 'sort_order' => 1]);
        Currency::create(['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol' => 'ر.س', 'decimals' => 2, 'is_base' => false, 'is_active' => true, 'sort_order' => 2]);

        ExchangeRate::create(['currency_code' => 'YER', 'rate_to_base' => 1, 'effective_date' => $this->oldDate]);
        ExchangeRate::create(['currency_code' => 'SAR', 'rate_to_base' => 100, 'effective_date' => $this->oldDate]);
        ExchangeRate::create(['currency_code' => 'YER', 'rate_to_base' => 1, 'effective_date' => now()->subDay()->toDateString()]);
        ExchangeRate::create(['currency_code' => 'SAR', 'rate_to_base' => 200, 'effective_date' => now()->subDay()->toDateString()]);
    }

    #[Test]
    public function a_new_listing_is_converted_at_todays_rate(): void
    {
        $listing = Listing::factory()->create([
            'price_amount' => 100,
            'currency_code' => 'SAR',
        ]);

        $this->assertSame(200.0, (float) $listing->rate_used);
        $this->assertSame(20000.0, (float) $listing->price_in_base);
        $this->assertSame(now()->toDateString(), $listing->rate_date->toDateString());
    }

    #[Test]
    public function changing_the_price_reconverts_at_the_rate_of_the_day_of_the_change(): void
    {
        $listing = $this->listingFrozenInThePast();

        $listing->price_amount = 200;
        $listing->save();

        // لا 200×100 = 20,000 (سعر ما قبل ثلاثة أشهر) بل 200×200 = 40,000
        $this->assertSame(200.0, (float) $listing->rate_used);
        $this->assertSame(40000.0, (float) $listing->price_in_base);
        $this->assertSame(now()->toDateString(), $listing->rate_date->toDateString());
    }

    #[Test]
    public function backfilling_an_empty_base_price_keeps_the_listings_own_historical_rate(): void
    {
        $listing = $this->listingFrozenInThePast();

        DB::table('listings')->where('id', $listing->id)->update(['price_in_base' => null]);

        $listing = Listing::find($listing->id);
        $listing->save();

        // إعادة الحساب لا تعيد تسعير الماضي بسعر اليوم
        $this->assertSame(100.0, (float) $listing->rate_used);
        $this->assertSame(10000.0, (float) $listing->price_in_base);
        $this->assertSame($this->oldDate, $listing->rate_date->toDateString());
    }

    #[Test]
    public function a_price_change_is_recorded_in_the_history(): void
    {
        $listing = $this->listingFrozenInThePast();

        $listing->price_amount = 250;
        $listing->save();

        $this->assertDatabaseHas('listing_price_history', [
            'listing_id' => $listing->id,
            'old_amount' => 100.00,
            'new_amount' => 250.00,
        ]);
    }

    #[Test]
    public function saving_without_touching_the_price_writes_no_history_row(): void
    {
        $listing = $this->listingFrozenInThePast();

        $listing->title = 'عنوان معدّل بلا تغيير سعر';
        $listing->save();

        $this->assertDatabaseCount('listing_price_history', 0);
    }

    /** إعلان قديم: سعره محسوب بسعر صرف ما قبل ثلاثة أشهر */
    private function listingFrozenInThePast(): Listing
    {
        $listing = Listing::factory()->create([
            'price_amount' => 100,
            'currency_code' => 'SAR',
            'published_at' => now()->subMonths(3),
        ]);

        DB::table('listings')->where('id', $listing->id)->update([
            'rate_used' => 100,
            'rate_date' => $this->oldDate,
            'price_in_base' => 10000,
        ]);

        return Listing::find($listing->id);
    }
}