<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * العيب المُصلَح: تعديل السعر كان يُحوَّل بسعر صرف تاريخ الإنشاء
 * لا تاريخ التعديل، لأن rate_date كان يُمرَّر دائماً ولا يُصفَّر.
 *
 * سعر الريال السعودي في هذه التجهيزات يتضاعف بين التاريخين (100 ← 200)
 * حتى يكون الفرق بين المنطقين ظاهراً في الرقم لا في النية.
 */
class ListingConversionDateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    private Location $location;

    private string $oldDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oldDate = now()->subMonths(3)->toDateString();
        $recentDate = now()->subDay()->toDateString();

        foreach ([['YER', 'ريال يمني', true], ['SAR', 'ريال سعودي', false]] as [$code, $name, $isBase]) {
            Currency::create([
                'code' => $code, 'name_ar' => $name, 'name_en' => $code,
                'symbol' => $code, 'decimals' => 2, 'is_base' => $isBase,
            ]);
        }

        $rates = [
            ['YER', 1, $this->oldDate],
            ['SAR', 100, $this->oldDate],
            ['YER', 1, $recentDate],
            ['SAR', 200, $recentDate],
        ];

        foreach ($rates as [$code, $rate, $date]) {
            ExchangeRate::create([
                'currency_code' => $code,
                'rate_to_base' => $rate,
                'effective_date' => $date,
                'source' => 'test',
                'created_at' => now(),
            ]);
        }

        $this->user = User::factory()->create();
        $this->category = Category::create(['name_ar' => 'إلكترونيات', 'slug' => 'electronics', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
    }

    #[Test]
    public function a_new_listing_is_converted_at_todays_rate(): void
    {
        $listing = $this->listing();

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
    public function changing_only_the_currency_also_reconverts_at_todays_rate(): void
    {
        $listing = $this->listingFrozenInThePast();

        $listing->currency_code = 'YER';
        $listing->save();

        $this->assertSame(1.0, (float) $listing->rate_used);
        $this->assertSame(100.0, (float) $listing->price_in_base);
    }

    #[Test]
    public function backfilling_an_empty_base_price_keeps_the_listings_own_historical_rate(): void
    {
        $listing = $this->listingFrozenInThePast();

        DB::table('listings')->where('id', $listing->id)->update(['price_in_base' => null]);

        $listing = Listing::find($listing->id);
        $listing->save();

        // إعادة الحساب لعمود فارغ لا تعيد تسعير الماضي بسعر اليوم
        $this->assertSame(100.0, (float) $listing->rate_used);
        $this->assertSame(10000.0, (float) $listing->price_in_base);
        $this->assertSame($this->oldDate, $listing->rate_date->toDateString());
    }

    #[Test]
    public function a_price_change_is_recorded_in_the_history(): void
    {
        $listing = $this->listingFrozenInThePast();

        $listing->update(['price_amount' => 250]);

        $history = $listing->priceHistory()->first();

        $this->assertNotNull($history);
        $this->assertSame(100.0, (float) $history->old_amount);
        $this->assertSame(250.0, (float) $history->new_amount);
        $this->assertSame(50000.0, (float) $history->new_in_base);
    }

    #[Test]
    public function saving_without_touching_the_price_writes_no_history_row(): void
    {
        $listing = $this->listingFrozenInThePast();

        $listing->update(['title' => 'عنوان معدّل بلا تغيير سعر']);

        $this->assertSame(0, $listing->priceHistory()->count());
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::create(array_merge([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'لابتوب ديل انسبايرون',
            'description' => 'الحالة ممتازة والاستخدام بسيط.',
            'price_amount' => 100,
            'currency_code' => 'SAR',
            'status' => 'active',
            'published_at' => now(),
        ], $attributes));
    }

    /**
     * إعلان قديم: سعره محسوب بسعر صرف ما قبل ثلاثة أشهر.
     *
     * التجميد يجري بـDB::table لا بالموديل، لأن الحفظ بالموديل يستدعي
     * الـObserver فيعيد الحساب بسعر اليوم — وهو بالضبط ما نريد اختباره لاحقاً.
     */
    private function listingFrozenInThePast(): Listing
    {
        $listing = $this->listing(['published_at' => now()->subMonths(3)]);

        DB::table('listings')->where('id', $listing->id)->update([
            'rate_used' => 100,
            'rate_date' => $this->oldDate,
            'price_in_base' => 10000,
        ]);

        return Listing::find($listing->id);
    }
}