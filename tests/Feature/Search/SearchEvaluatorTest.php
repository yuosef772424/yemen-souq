<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\Services\SearchEvaluator;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * المقياس نفسه يُختبر.
 *
 * مقياس لا يُختبر أخطر من غياب المقياس: رقم يُطمئن بلا سبب. أهم اختبار
 * هنا هو الثالث — الحالة التي لا يوجد لها إعلان يجب أن تُعلَّم «متروكة»
 * لا «ساقطة»، وإلا لصار الرقم كاذباً كلما نقصت البيانات.
 */
class SearchEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private SearchEvaluator $evaluator;

    private User $user;

    private Category $category;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->category = Category::create(['name_ar' => 'عقارات', 'slug' => 'property', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);

        $this->evaluator = app(SearchEvaluator::class);
    }

    private function listing(string $title): Listing
    {
        return Listing::create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => $title,
            'price_amount' => 5000000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);
    }

    #[Test]
    public function a_satisfied_case_passes(): void
    {
        $this->listing('شقة للإيجار في حدة');

        $report = $this->evaluator->run([
            ['name' => 'إيجار', 'query' => 'شقه للايجار', 'expect_any' => ['للايجار'], 'min_results' => 1],
        ]);

        $this->assertSame(1, $report['passed']);
        $this->assertSame(0, $report['failed']);
    }

    /** المخالفة تُعدّ منفصلة: نتيجة خاطئة أخطر من نتيجة ناقصة */
    #[Test]
    public function a_forbidden_result_is_counted_as_a_violation(): void
    {
        $this->listing('شقة للبيع في حدة');

        $report = $this->evaluator->run([
            ['name' => 'إيجار', 'query' => 'شقه', 'forbid_all' => ['للبيع']],
        ]);

        $this->assertSame(1, $report['failed']);
        $this->assertSame(1, $report['violations']);
    }

    #[Test]
    public function a_case_without_matching_data_is_skipped_not_failed(): void
    {
        $report = $this->evaluator->run([
            ['name' => 'سلعة غائبة', 'query' => 'غواصه', 'expect_any' => ['غواصه'], 'min_results' => 1],
        ]);

        $this->assertSame(1, $report['skipped']);
        $this->assertSame(0, $report['failed']);
    }

    #[Test]
    public function a_present_listing_that_is_not_found_is_a_real_failure(): void
    {
        // الإعلان موجود، والاستعلام لا يجده: هذا عيب في المحرك لا في البيانات
        $this->listing('شقة للإيجار في حدة');

        $report = $this->evaluator->run([
            ['name' => 'كلمة لا تطابق', 'query' => 'زززز', 'expect_any' => ['للايجار'], 'min_results' => 1],
        ]);

        $this->assertSame(1, $report['failed']);
        $this->assertSame(0, $report['skipped']);
    }

    #[Test]
    public function an_expected_zero_that_returns_results_is_a_violation(): void
    {
        $this->listing('شقة للإيجار في حدة');

        $report = $this->evaluator->run([
            ['name' => 'يجب صفر', 'query' => 'شقه', 'expect_zero' => true],
        ]);

        $this->assertSame(1, $report['violations']);
    }

    #[Test]
    public function the_expected_match_mode_is_checked(): void
    {
        $this->listing('شقة للإيجار في حدة');

        $report = $this->evaluator->run([
            ['name' => 'تام لا متراجع', 'query' => 'شقه للايجار', 'modes' => ['near'], 'min_results' => 1],
        ]);

        $this->assertSame(1, $report['failed']);
    }

    #[Test]
    public function the_shipped_golden_set_is_valid_and_runnable(): void
    {
        $cases = require base_path('database/data/search-golden-set.php');

        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertArrayHasKey('query', $case);
            $this->assertArrayHasKey('name', $case);
        }

        // القاعدة هنا شبه فارغة، فالمتوقّع «متروك» لا «ساقط» —
        // وهذا نفسه ما يثبت أن التمييز يعمل
        $report = $this->evaluator->run($cases);

        $this->assertSame(0, $report['violations']);
    }
}