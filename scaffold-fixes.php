<?php

/**
 * scaffold-fix-tests.php — إعادة كتابة ملفي الاختبار الفاشلين.
 *
 * يُشغَّل من جذر المشروع:  php scaffold-fix-tests.php
 *
 * الخطأ الذي أُصلح: الاختباران استخدما Listing::factory() وافترضا قاعدة
 * مزروعة. RefreshDatabase يبني جداول فارغة، فالمصنع لم يجد تصنيفاً
 * فمرّر category_id = null، ولم توجد أسعار صرف أصلاً.
 *
 * الأسلوب هنا هو نفسه المتّبع في ListingObserverTest و SearchQueryBuilderTest:
 * تجهيزات صريحة في setUp و Listing::create بلا مصنع. الاتساق مع الاختبارات
 * القائمة أهم من أناقة المصنع — من يقرأ الملف الجديد يجد ما يعرفه.
 */

if (! is_dir(__DIR__ . '/tests/Feature')) {
    fwrite(STDERR, "خطأ: شغّل هذا السكربت من جذر المشروع.\n");
    exit(1);
}

$files = [];

$files['tests/Feature/Catalog/ListingConversionDateTest.php'] = <<<'PHP'
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
PHP;

$files['tests/Feature/Catalog/ListingVisibilityTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * العيبان المُصلَحان: published() كان يتجاهل expires_at، وترتيب المميّز
 * كان يتجاهل featured_until — مع وجود فهرس لكل منهما في الجدول.
 *
 * لم يسقط أي اختبار قائم عند الإصلاح، لأن الاختبارات القائمة تترك
 * العمودين null. هذا الملف يغطي الحالتين اللتين لم تكونا مغطّاتين.
 */
class ListingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $category;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['YER', true], ['USD', false]] as [$code, $isBase]) {
            Currency::create([
                'code' => $code, 'name_ar' => $code, 'name_en' => $code,
                'symbol' => $code, 'decimals' => 2, 'is_base' => $isBase,
            ]);
        }

        foreach (['YER' => 1, 'USD' => 500] as $code => $rate) {
            ExchangeRate::create([
                'currency_code' => $code,
                'rate_to_base' => $rate,
                'effective_date' => now()->subYear()->toDateString(),
                'source' => 'test',
                'created_at' => now(),
            ]);
        }

        $this->user = User::factory()->create();
        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
    }

    #[Test]
    public function an_expired_listing_is_not_published(): void
    {
        $expired = $this->listing(['expires_at' => now()->subDay()]);
        $live = $this->listing(['expires_at' => now()->addDays(10)]);

        $ids = Listing::published()->pluck('id')->all();

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    #[Test]
    public function a_listing_without_an_expiry_date_stays_published(): void
    {
        $listing = $this->listing(['expires_at' => null]);

        $this->assertContains($listing->id, Listing::published()->pluck('id')->all());
    }

    #[Test]
    public function an_expired_promotion_no_longer_ranks_first(): void
    {
        $stale = $this->listing([
            'is_featured' => true,
            'featured_until' => now()->subDay(),
            'published_at' => now()->subMonth(),
        ]);

        $ordinary = $this->listing([
            'is_featured' => false,
            'published_at' => now(),
        ]);

        $ids = Listing::published()->featuredFirst()->orderByDesc('published_at')->pluck('id')->all();

        $this->assertSame($ordinary->id, $ids[0]);
        $this->assertFalse($stale->fresh()->isFeaturedNow());
    }

    #[Test]
    public function a_live_promotion_still_ranks_first(): void
    {
        $featured = $this->listing([
            'is_featured' => true,
            'featured_until' => now()->addWeek(),
            'published_at' => now()->subMonth(),
        ]);

        $this->listing(['is_featured' => false, 'published_at' => now()]);

        $ids = Listing::published()->featuredFirst()->orderByDesc('published_at')->pluck('id')->all();

        $this->assertSame($featured->id, $ids[0]);
        $this->assertTrue($featured->fresh()->isFeaturedNow());
    }

    #[Test]
    public function a_promotion_without_an_end_date_stays_first(): void
    {
        $featured = $this->listing([
            'is_featured' => true,
            'featured_until' => null,
            'published_at' => now()->subMonth(),
        ]);

        $this->listing(['is_featured' => false, 'published_at' => now()]);

        $ids = Listing::published()->featuredFirst()->orderByDesc('published_at')->pluck('id')->all();

        $this->assertSame($featured->id, $ids[0]);
    }

    #[Test]
    public function the_arabic_slug_survives(): void
    {
        $listing = $this->listing(['title' => 'تويوتا كورولا موديل 2018']);

        // كان الناتج listing-x8f3k2m1 لكل إعلان بلا استثناء
        $this->assertStringContainsString('تويوتا', $listing->slug);
        $this->assertStringContainsString('2018', $listing->slug);
        $this->assertStringNotContainsString(' ', $listing->slug);
    }

    #[Test]
    public function two_listings_with_the_same_title_get_different_slugs(): void
    {
        $first = $this->listing(['title' => 'شقة للإيجار في حدة']);
        $second = $this->listing(['title' => 'شقة للإيجار في حدة']);

        $this->assertNotSame($first->slug, $second->slug);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::create(array_merge([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'سيارة تويوتا كورولا',
            'description' => 'الحالة ممتازة.',
            'price_amount' => 1000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ], $attributes));
    }
}
PHP;

$written = 0;

foreach ($files as $relativePath => $contents) {
    $path = __DIR__ . '/' . $relativePath;
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fwrite(STDERR, "فشل إنشاء المجلد: {$directory}\n");
        exit(1);
    }

    if (file_put_contents($path, str_replace("\r\n", "\n", $contents)) === false) {
        fwrite(STDERR, "فشل الكتابة: {$relativePath}\n");
        exit(1);
    }

    echo "✓ {$relativePath}\n";
    $written++;
}

echo "\nكُتب {$written} ملفاً.\n\n";
echo "  php artisan test\n\n";
echo "الْتزام مقترح:\n";
echo "  test: build fixtures explicitly instead of relying on a seeded database\n";