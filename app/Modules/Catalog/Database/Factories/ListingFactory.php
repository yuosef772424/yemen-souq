<?php

namespace App\Modules\Catalog\Database\Factories;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /** عناوين واقعية حسب التصنيف — أفضل من نص عشوائي بلا معنى */
    private const TITLES = [
        'vehicles' => [
            'تويوتا كورولا موديل 2018 وكالة',
            'هيونداي النترا نظيفة جداً',
            'كيا ريو مستعملة بحالة ممتازة',
            'تويوتا هايلكس دبل غمارة',
            'نيسان صني اقتصادية',
            'دراجة نارية هوندا 150',
            'قطع غيار تويوتا أصلية',
            'شاحنة إيسوزو للنقل',
            'ميتسوبيشي لانسر فل كامل',
            'سيارة مرسيدس قديمة للبيع',
        ],
        'real-estate' => [
            'شقة للإيجار في حدة ثلاث غرف',
            'أرض تجارية على شارعين',
            'بيت شعبي للبيع في المنصورة',
            'شقة تمليك جديدة مفروشة',
            'محل تجاري في شارع الزبيري',
            'فيلا دورين مع حديقة',
            'مكتب إداري مؤثث بالكامل',
            'أرض سكنية في مديرية معين',
        ],
        'electronics' => [
            'آيفون 14 برو ماكس مستعمل',
            'لابتوب ديل انسبايرون كور i7',
            'سامسونج جالكسي S23 جديد',
            'شاشة تلفزيون 55 بوصة سمارت',
            'كاميرا كانون احترافية مع عدسات',
            'ماك بوك برو 2021 بحالة الوكالة',
            'جهاز بلايستيشن 5 مع ألعاب',
            'شاومي ريدمي نوت 13',
        ],
        'furniture' => [
            'غرفة نوم كاملة خشب زان',
            'مجلس عربي مستعمل نظيف',
            'ثلاجة سامسونج نوفروست',
            'مطبخ ألمنيوم بالتركيب',
            'كنب زاوية جلد طبيعي',
            'غسالة أوتوماتيك 8 كيلو',
        ],
        'fashion' => [
            'ساعة رجالية أصلية',
            'عطر فرنسي أصلي كمية محدودة',
            'ثوب رجالي فاخر مقاس L',
            'حقيبة نسائية جلد',
        ],
        'services' => [
            'خدمة نقل عفش داخل صنعاء',
            'دروس خصوصية رياضيات وفيزياء',
            'تصميم مواقع وتطبيقات',
            'صيانة مكيفات وثلاجات',
        ],
        'jobs' => [
            'مطلوب محاسب بخبرة سنتين',
            'مطلوب مندوب مبيعات',
            'أبحث عن عمل مبرمج',
        ],
        'animals' => [
            'أغنام عواسي للبيع',
            'طيور زينة كناري',
            'مستلزمات زراعية ومضخات',
        ],
    ];

    public function definition(): array
    {
        $category = Category::whereNotNull('parent_id')->inRandomOrder()->first();
        $rootSlug = $this->rootSlugFor($category);
        $titles = self::TITLES[$rootSlug] ?? self::TITLES['electronics'];

        $currency = $this->faker->randomElement(['YER', 'YER', 'SAR', 'USD']);

        return [
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'category_id' => $category?->id,
            'location_id' => Location::where('level', 'district')->inRandomOrder()->first()?->id,
            'title' => $this->faker->randomElement($titles),
            'description' => $this->description(),
            'price_amount' => $this->priceFor($currency),
            'currency_code' => $currency,
            'price_type' => $this->faker->randomElement(['fixed', 'fixed', 'fixed', 'negotiable']),
            'is_negotiable' => $this->faker->boolean(40),
            'sale_type' => 'direct',
            'condition_type' => $this->faker->randomElement(['new', 'like_new', 'used', 'used']),
            'status' => 'active',
            'contact_phone' => '+9677'.$this->faker->numerify('########'),
            'is_featured' => $this->faker->boolean(10),
            'published_at' => $this->faker->dateTimeBetween('-60 days', 'now'),
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * نطاقات سعرية واقعية لكل عملة.
     * المهم هنا: نفس الرقم يعني قيمة مختلفة تماماً حسب العملة —
     * وهذا ما يجعل بيانات الاختبار تُظهر المشكلة فعلاً.
     */
    private function priceFor(string $currency): float
    {
        return match ($currency) {
            'USD' => $this->faker->randomElement([50, 100, 150, 200, 350, 500, 1200, 3500, 8000]),
            'SAR' => $this->faker->randomElement([100, 200, 500, 800, 1500, 3000, 7000, 15000]),
            default => $this->faker->randomElement([5000, 15000, 40000, 90000, 250000, 800000, 2500000]),
        };
    }

    private function description(): string
    {
        $parts = [
            'الحالة ممتازة والاستخدام بسيط.',
            'السعر قابل للتفاوض للجاد فقط.',
            'التواصل عبر الرقم المرفق في أي وقت.',
            'يوجد صور إضافية عند الطلب.',
            'البيع لعدم الحاجة.',
            'الفحص مسموح قبل الشراء.',
        ];

        shuffle($parts);

        return implode(' ', array_slice($parts, 0, 3));
    }

    private function rootSlugFor(?Category $category): string
    {
        if ($category === null) {
            return 'electronics';
        }

        $rootId = (int) explode('/', $category->path)[0];

        return Category::find($rootId)?->slug ?? 'electronics';
    }

    /** إعلان بعملة محددة — مفيد في الاختبارات */
    public function inCurrency(string $code, ?float $amount = null): static
    {
        return $this->state(fn (): array => [
            'currency_code' => $code,
            'price_amount' => $amount ?? $this->priceFor($code),
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => [
            'is_featured' => true,
            'featured_until' => now()->addDays(14),
        ]);
    }
}
