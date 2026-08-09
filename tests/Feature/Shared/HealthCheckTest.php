<?php

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Support\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * الفحص نفسه يُختبَر: أداة تشخيص تكذب أسوأ من غيابها.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private HealthCheck $health;

    private User $user;

    private Category $category;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->health = app(HealthCheck::class);

        Currency::create([
            'code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'YER',
            'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true,
        ]);

        ExchangeRate::create([
            'currency_code' => 'YER', 'rate_to_base' => 1,
            'effective_date' => now()->subYear()->toDateString(),
            'source' => 'test', 'created_at' => now(),
        ]);

        $this->user = User::factory()->create();
        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
    }

    #[Test]
    public function it_detects_an_empty_search_blob(): void
    {
        $listing = $this->listing();

        $this->assertSame('ok', $this->statusOf('search_blob'));

        DB::table('listings')->where('id', $listing->id)->update(['search_blob' => null]);

        $this->assertSame('fail', $this->statusOf('search_blob'));
    }

    #[Test]
    public function it_detects_a_priced_listing_without_a_base_value(): void
    {
        $listing = $this->listing();

        $this->assertSame('ok', $this->statusOf('price_in_base'));

        DB::table('listings')->where('id', $listing->id)->update(['price_in_base' => null]);

        $this->assertSame('fail', $this->statusOf('price_in_base'));
    }

    #[Test]
    public function it_detects_a_missing_base_currency(): void
    {
        $this->assertSame('ok', $this->statusOf('عملة الأساس'));

        DB::table('currencies')->update(['is_base' => false]);

        $this->assertSame('fail', $this->statusOf('عملة الأساس'));
    }

    #[Test]
    public function it_detects_an_active_currency_without_a_rate(): void
    {
        Currency::create([
            'code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'SAR',
            'symbol' => 'ر.س', 'decimals' => 2, 'is_base' => false,
        ]);

        $this->assertSame('fail', $this->statusOf('أسعار الصرف السارية'));
    }

    #[Test]
    public function it_flags_an_expired_listing_that_is_still_active(): void
    {
        $listing = $this->listing();

        DB::table('listings')->where('id', $listing->id)->update(['expires_at' => now()->subDay()]);

        $this->assertSame('warn', $this->statusOf('إعلانات منتهية'));
    }

    #[Test]
    public function it_reports_no_failures_on_a_healthy_database(): void
    {
        $this->listing();
        $this->health->run();

        $this->assertFalse($this->health->hasFailures());
    }

    private function statusOf(string $name): string
    {
        foreach ($this->health->run() as $result) {
            if ($result['name'] === $name) {
                return $result['status'];
            }
        }

        $this->fail("لا يوجد فحص باسم {$name}");
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::create(array_merge([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'سيارة تويوتا كورولا',
            'description' => 'الحالة ممتازة.',
            'price_amount' => 100000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ], $attributes));
    }
}