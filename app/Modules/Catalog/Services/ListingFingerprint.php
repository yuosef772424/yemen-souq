<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Services\ArabicNormalizer;

/**
 * بصمة محتوى الإعلان — تجزئة لما يجعله «نفس الشيء المعروض».
 *
 * ═══ لماذا عمود وليس مقارنة وقت الاستعلام ═══
 *
 * كشف التكرار بمقارنة عنوان ووصف وسعر وتصنيف وموقع يعني خمسة شروط
 * على أعمدة نصّية طويلة، بلا فهرس يخدمها. والبصمة تحوّل ذلك كله إلى
 * مطابقة `char(64)` واحدة على فهرس `(user_id, fingerprint)`.
 *
 * ═══ وماذا يدخل فيها وماذا لا ═══
 *
 * يدخل ما لو تغيّر لصار الإعلان إعلاناً آخر: العنوان والوصف — **مطبَّعين**
 * فلا تُفلت نسخةٌ اختلفت بهمزةٍ أو مسافة — والتصنيف والموقع والسعر
 * وعملته ونوعه وحالة السلعة.
 *
 * ولا يدخل: `contact_phone` و`hide_phone` و`is_negotiable`. تغييرها لا
 * يجعل السلعة سلعةً ثانية، وإدخالها يعني أن تعديل رقم التواصل يفتح
 * الباب لنشر نفس الإعلان مرة أخرى.
 *
 * ولا تدخل المواصفات (`listing_attribute_values`): تُكتب بعد صفّ الإعلان
 * في نفس المعاملة، فلا تكون معروفة وقت حساب البصمة. والأثر مقبول —
 * إعلانان بنفس العنوان والوصف والسعر يختلفان في الممشى وحده حالةٌ
 * نادرة، ومن يقع فيها يغيّر العنوان.
 *
 * ═══ ولماذا التطبيع هنا لا قراءة العمود المطبَّع ═══
 *
 * `title_normalized` يملؤه الـObserver في نفس دورة الحفظ، والحارس يحتاج
 * البصمة **قبل** إنشاء أي نموذج — من مصفوفة الطلب. فالتطبيع يجري في
 * هذا الصنف ليعطي المسارين نفس الجواب.
 */
class ListingFingerprint
{
    public function __construct(
        private readonly ArabicNormalizer $normalizer,
    ) {}

    public function for(Listing $listing): string
    {
        return $this->hash([
            'title' => $listing->title,
            'description' => $listing->description,
            'category_id' => $listing->category_id,
            'location_id' => $listing->location_id,
            'price_type' => $listing->price_type,
            'price_amount' => $listing->price_amount,
            'currency_code' => $listing->currency_code,
            'condition_type' => $listing->condition_type,
        ]);
    }

    /**
     * البصمة من بيانات طلبٍ محقَّقة — قبل وجود نموذج.
     *
     * @param  array<string, mixed>  $data
     */
    public function ofInput(array $data): string
    {
        return $this->hash($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hash(array $data): string
    {
        $parts = [
            $this->normalizer->normalize((string) ($data['title'] ?? '')),
            $this->normalizer->normalize((string) ($data['description'] ?? '')),
            (string) (int) ($data['category_id'] ?? 0),
            (string) (int) ($data['location_id'] ?? 0),
            (string) ($data['price_type'] ?? ''),
            $this->amount($data['price_amount'] ?? null),
            (string) ($data['currency_code'] ?? ''),
            (string) ($data['condition_type'] ?? ''),
        ];

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * السعر بخانتين دائماً.
     *
     * `1500` من الطلب و`1500.00` من العمود `decimal:2` نفس المبلغ ونصّان
     * مختلفان — وبلا هذا التوحيد تختلف بصمة الطلب عن بصمة الصفّ المحفوظ،
     * فلا يلتقيان أبداً.
     */
    private function amount(mixed $value): string
    {
        return $value === null || $value === ''
            ? ''
            : number_format((float) $value, 2, '.', '');
    }
}
