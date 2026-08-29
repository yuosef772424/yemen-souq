<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بنية صفحة النتائج، لا مظهرها.
 *
 * الحارس الأهم هنا هو تداخل النماذج: صفحة النتائج كانت تلفّ كل شيء —
 * الفلاتر والقائمة معاً — في `<form method="GET">`. ثم صارت البطاقة تحمل
 * زرّ مفضلة، وهو نموذج POST. ونموذجٌ داخل نموذج **HTML غير صالح**: المتصفح
 * يتجاهل الداخلي بلا إنذار ولا خطأ في السجلّ، فيضغط المستخدم القلب ولا
 * يحدث شيء. لا اختبار قائم يكشف ذلك لأن الخادم سليم تماماً — العطل في
 * الترميز وحده.
 */
class ResultsPageStructureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Category $child;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'YER',
            'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true,
        ]);

        ExchangeRate::create([
            'currency_code' => 'YER', 'rate_to_base' => 1,
            'effective_date' => now()->toDateString(), 'source' => 'test', 'created_at' => now(),
        ]);

        $root = Category::create(['name_ar' => 'سيارات ومركبات', 'slug' => 'vehicles', 'path' => '']);
        $this->child = Category::create([
            'parent_id' => $root->id, 'name_ar' => 'سيارات للبيع', 'slug' => 'vehicles-1', 'path' => '',
        ]);
        Category::create([
            'parent_id' => $root->id, 'name_ar' => 'دراجات نارية', 'slug' => 'vehicles-2', 'path' => '',
        ]);

        $governorate = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
        $district = Location::create([
            'parent_id' => $governorate->id, 'name_ar' => 'التحرير', 'slug' => 'tahrir',
            'level' => 'district', 'path' => '',
        ]);

        $this->user = User::create([
            'name' => 'زائر مختبَر', 'phone' => '+967771234567',
            'email' => 'visitor@example.test', 'password' => Hash::make('password1234'),
        ]);
        $this->user->markPhoneAsVerified();

        Listing::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->child->id,
            'location_id' => $district->id,
            'currency_code' => 'YER',
            'title' => 'تويوتا كورولا للاختبار',
            'status' => 'active',
        ]);
    }

    /**
     * الحارس الحقيقي: نموذج المفضلة في البطاقة يجب ألّا يقع داخل نموذج
     * الفلاتر. يُفحص بعدّ الوسوم لا بتحليل DOM كامل — `<form` لا يُغلق
     * إلا بـ`</form>`، فأي فتحٍ ثانٍ قبل الإغلاق تداخل.
     */
    #[Test]
    public function the_favorite_form_is_never_nested_inside_the_filter_form(): void
    {
        $html = $this->actingAs($this->user)->get('/')->assertOk()->getContent();

        $depth = 0;
        $maxDepth = 0;

        foreach (preg_split('/(<form\b|<\/form>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            if (stripos($piece, '<form') === 0) {
                $maxDepth = max($maxDepth, ++$depth);
            } elseif (stripos($piece, '</form>') === 0) {
                $depth--;
            }
        }

        $this->assertSame(0, $depth, 'كل نموذج مفتوح يجب أن يُغلق.');
        $this->assertSame(
            1,
            $maxDepth,
            'نموذج داخل نموذج ترميزٌ غير صالح: المتصفح يتجاهل الداخلي بصمت، '
            .'فيتوقف زرّ المفضلة في البطاقة بلا أي خطأ ظاهر.'
        );
    }

    /**
     * الفرز يقع خارج لوحة الفلاتر بعد الفصل، ولا يصل إليها إلا بالسمة
     * `form`. حذفها يجعل تغيير الترتيب يُرسل نموذجاً فارغاً — أي يمسح كل
     * فلتر مُطبَّق بصمت.
     */
    #[Test]
    public function the_sort_control_stays_bound_to_the_filter_form(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="sort"[^>]*form="search-filters"/',
            $html,
            'الفرز خارج لوحة الفلاتر، فبلا form="search-filters" يفقد كل الفلاتر عند التغيير.'
        );

        $this->assertStringContainsString('id="search-filters"', $html);
    }

    /** كلمة البحث تُكتب في الترويسة، فيجب أن تنجو من تطبيق فلتر */
    #[Test]
    public function the_query_survives_applying_a_filter(): void
    {
        $html = $this->get('/?q=كورولا')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="hidden"[^>]*name="q"[^>]*value="كورولا"/u',
            $html,
            'بلا حقل q خفيّ في لوحة الفلاتر، تطبيق أي فلتر يمسح كلمة البحث.'
        );
    }

    #[Test]
    public function the_heading_carries_the_category_the_place_and_the_count(): void
    {
        $this->get('/?category='.$this->child->id)
            ->assertOk()
            ->assertSee('سيارات للبيع في اليمن', false)
            // مسار التصفّح يصل الفرع بجذره
            ->assertSee('سيارات ومركبات', false)
            // وشرائح الإخوة تتيح الانتقال جانباً بلا فتح لوحة الفلاتر
            ->assertSee('دراجات نارية', false);
    }
}
