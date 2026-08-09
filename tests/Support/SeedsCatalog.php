<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;

/**
 * الحد الأدنى من البيانات المرجعية لأي اختبار يلمس إعلاناً.
 *
 * لماذا trait: خمسة ملفات اختبار جديدة تحتاج نفس setUp من عشرين سطراً
 * (عملات + أسعار صرف + تصنيف + موقع). نسخه خمس مرات يعني أن إضافة
 * عملة ثالثة لاحقاً تتطلب خمس تعديلات — وسينسى أحدها.
 *
 * البديل المرفوض: استدعاء seeders الحقيقية. تُنشئ 22 محافظة و37 تصنيفاً
 * و156 إعلاناً لكل اختبار، فيصبح الملف الواحد أبطأ من كل ما سبقه.
 */
trait SeedsCatalog
{
    protected Category $category;

    protected Location $location;

    protected function seedReferenceData(): void
    {
        foreach ([['YER', true], ['SAR', false], ['USD', false]] as [$code, $isBase]) {
            Currency::create([
                'code' => $code, 'name_ar' => $code, 'name_en' => $code,
                'symbol' => $code, 'decimals' => 2, 'is_base' => $isBase,
            ]);
        }

        foreach (['YER' => 1, 'SAR' => 140, 'USD' => 530] as $code => $rate) {
            ExchangeRate::create([
                'currency_code' => $code,
                'rate_to_base' => $rate,
                'effective_date' => now()->subYear()->toDateString(),
                'source' => 'test',
                'created_at' => now(),
            ]);
        }

        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
    }

    /**
     * إعلان بـListing::create لا بـListingFactory، بقصد.
     *
     * الـfactory يختار تصنيفاً بـwhereNotNull('parent_id') — وهو استعلام
     * يُرجع null في تركيبة اختبار فيها تصنيف جذر واحد، فيسقط الإدراج على
     * قيد NOT NULL برسالة لا علاقة لها بما يُختبر. الإنشاء الصريح أطول
     * سطرين وأصدق: بيانات الاختبار مرئية في مكانها.
     */
    protected function listingFor(User $user, array $attributes = []): Listing
    {
        return Listing::create([
            'user_id' => $user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'سيارة تويوتا كورولا موديل 2018',
            'description' => 'الحالة ممتازة والاستخدام بسيط.',
            'price_amount' => 5000,
            'currency_code' => 'SAR',
            'price_type' => 'fixed',
            'sale_type' => 'direct',
            'status' => 'active',
            'contact_phone' => '+967771234567',
            'published_at' => now(),
            'expires_at' => now()->addDays(30),
            ...$attributes,
        ]);
    }
}