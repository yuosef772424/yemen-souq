<?php

namespace Tests\Feature\Search;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Search\Services\SynonymGate;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * البوابة.
 *
 * ما تحرسه هذه الاختبارات ليس الكود بل القاعدة: لا يدخل مكافئ إلى
 * الجدول الحيّ لأنه يبدو صحيحاً لغوياً، بل لأن بياناتك تحتمله. وأي
 * نموذج لغوي سيقترح «شقة ↔ بيت» بثقة تامة — والبوابة هي ما يمنعها
 * من الوصول إلى البحث.
 */
class SynonymGateTest extends TestCase
{
    use RefreshDatabase;

    private SynonymGate $gate;

    private User $user;

    private Category $appliances;

    private Category $property;

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
        $this->appliances = Category::create(['name_ar' => 'أجهزة', 'slug' => 'appliances', 'path' => '']);
        $this->property = Category::create(['name_ar' => 'عقارات', 'slug' => 'property', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);

        $this->gate = app(SynonymGate::class);
    }

    private function listings(string $title, Category $category, float $price, int $count = 4): void
    {
        for ($i = 0; $i < $count; $i++) {
            Listing::create([
                'user_id' => $this->user->id,
                'category_id' => $category->id,
                'location_id' => $this->location->id,
                'title' => "{$title} رقم " . ($i + 1),
                'price_amount' => $price,
                'currency_code' => 'YER',
                'status' => 'active',
                'published_at' => now(),
            ]);
        }
    }

    #[Test]
    public function a_pair_in_the_same_category_and_price_range_passes(): void
    {
        $this->listings('ثلاجة سامسونج', $this->appliances, 180000);
        $this->listings('براد ال جي', $this->appliances, 210000);

        $this->assertTrue($this->gate->assess('ثلاجة', 'براد')->passed());
    }

    /** القائمة الصريحة تسبق كل فحص إحصائي — ولا فحص يكتشفها */
    #[Test]
    public function a_forbidden_pair_is_rejected_before_anything_else(): void
    {
        $verdict = $this->gate->assess('للبيع', 'للايجار');

        $this->assertTrue($verdict->rejected());
        $this->assertStringContainsString('النقائض', $verdict->reason());
    }

    #[Test]
    public function the_pair_that_a_language_model_would_suggest_is_still_rejected(): void
    {
        $this->listings('شقة تمليك', $this->property, 20000000);
        $this->listings('بيت شعبي', $this->property, 30000000);

        $this->assertTrue($this->gate->assess('شقة', 'بيت')->rejected());
    }

    #[Test]
    public function two_words_from_different_category_trees_are_rejected(): void
    {
        $this->listings('ثلاجة سامسونج', $this->appliances, 180000);
        $this->listings('أرض تجارية', $this->property, 40000000);

        $verdict = $this->gate->assess('ثلاجة', 'أرض');

        $this->assertTrue($verdict->rejected());
        $this->assertStringContainsString('تصنيف', $verdict->reason());
    }

    /** نفس التصنيف لا يكفي: فارق سعري بعشرات الأضعاف يعني سلعتين */
    #[Test]
    public function a_wide_price_gap_is_rejected_even_inside_one_category(): void
    {
        $this->listings('كنب مستعمل', $this->appliances, 40000);
        $this->listings('طقم مجالس فاخر', $this->appliances, 6000000);

        $verdict = $this->gate->assess('كنب', 'طقم');

        $this->assertTrue($verdict->rejected());
        $this->assertStringContainsString('سعري', $verdict->reason());
    }

    /**
     * «لا أملك بيانات للحكم» ليس «مرفوض».
     *
     * خلطهما يجعل كل مكافئ لسلعة نادرة يُرفض إلى الأبد لأنها نادرة —
     * فيبقى البحث أضعف ما يكون حيث هو أضعف ما يكون.
     */
    #[Test]
    public function an_unknown_word_comes_back_unverified_not_rejected(): void
    {
        $this->listings('ثلاجة سامسونج', $this->appliances, 180000);

        $verdict = $this->gate->assess('ثلاجة', 'مبردة');

        $this->assertFalse($verdict->passed());
        $this->assertFalse($verdict->rejected());
    }

    #[Test]
    public function a_single_letter_is_never_a_synonym(): void
    {
        $this->assertTrue($this->gate->assess('ثلاجة', 'ث')->rejected());
    }

    #[Test]
    public function identical_words_after_normalization_are_rejected(): void
    {
        $this->assertTrue($this->gate->assess('سيارة', 'سياره')->rejected());
    }

    /** إعلان واحد بسعر خاطئ لا يُسقط زوجاً صحيحاً — الوسيط لا المتوسط */
    #[Test]
    public function one_absurd_price_does_not_reject_a_good_pair(): void
    {
        $this->listings('ثلاجة سامسونج', $this->appliances, 180000);
        $this->listings('براد ال جي', $this->appliances, 210000);

        Listing::create([
            'user_id' => $this->user->id,
            'category_id' => $this->appliances->id,
            'location_id' => $this->location->id,
            'title' => 'براد بسعر مكتوب خطأ',
            'price_amount' => 900000000,
            'currency_code' => 'YER',
            'status' => 'active',
            'published_at' => now(),
        ]);

        $this->assertTrue($this->gate->assess('ثلاجة', 'براد')->passed());
    }
}