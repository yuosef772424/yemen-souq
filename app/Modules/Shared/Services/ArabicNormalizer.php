<?php

namespace App\Modules\Shared\Services;

/**
 * تطبيع النص العربي لأغراض المطابقة والبحث.
 *
 * المشكلة التي يحلها: المستخدم يكتب "اعلانات" أو "إعلانات" أو "أعلانات"
 * ويتوقع نفس النتائج. المطابقة الحرفية تفشل في كل هذه الحالات.
 *
 * القاعدة: النص الأصلي يُعرض كما كتبه صاحبه — النص المطبّع يُستخدم للبحث فقط.
 */
final class ArabicNormalizer
{
    /** التشكيل والعلامات التي تُحذف بالكامل */
    private const DIACRITICS = [
        "\u{064B}", // تنوين فتح
        "\u{064C}", // تنوين ضم
        "\u{064D}", // تنوين كسر
        "\u{064E}", // فتحة
        "\u{064F}", // ضمة
        "\u{0650}", // كسرة
        "\u{0651}", // شدة
        "\u{0652}", // سكون
        "\u{0653}", // مدة
        "\u{0654}", // همزة فوق
        "\u{0655}", // همزة تحت
        "\u{0670}", // ألف خنجرية
        "\u{0640}", // تطويل (ـــ)
    ];

    /** توحيد الأحرف المتشابهة رسماً */
    private const LETTER_MAP = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي', 'ئ' => 'ي',
        'ؤ' => 'و',
       
    ];

    /** الأرقام العربية والفارسية إلى لاتينية */
    private const DIGIT_MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];
    /** علامات الترقيم العربية — يصنّفها PCRE ضمن \p{Arabic} فتنجو من التنظيف العام */
    private const ARABIC_PUNCTUATION = [
        '،', '؛', '؟', '٪', '٫', '٬', '٭', '۔', '؞', '؟',
    ];

    /**
     * يحوّل النص إلى صورته المطبّعة القابلة للمطابقة.
     */
    public function normalize(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(self::DIACRITICS, '', $text);
        $text = strtr($text, self::LETTER_MAP);
        $text = strtr($text, self::DIGIT_MAP);


        // علامات الترقيم العربية تحديداً — لا يلتقطها التعبير النمطي أدناه
        $text = str_replace(self::ARABIC_PUNCTUATION, ' ', $text);

        // علامات الترقيم العربية واللاتينية تصبح مسافات
        $text = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}\s]+/u', ' ', $text) ?? '';

        // توحيد المسافات المتكررة
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * يقسّم النص المطبّع إلى كلمات، ويستبعد الكلمات القصيرة جداً.
     *
     * @return array<int, string>
     */
    public function tokenize(?string $text, int $minLength = 2): array
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return [];
        }

        $tokens = array_filter(
            explode(' ', $normalized),
            fn (string $token): bool => mb_strlen($token, 'UTF-8') >= $minLength
        );

        return array_values(array_unique($tokens));
    }

    /**
     * يبني نصاً واحداً مطبّعاً من عدة أجزاء — يُستخدم لعمود search_blob.
     */
    public function buildBlob(string ...$parts): string
    {
        return $this->normalize(implode(' ', array_filter($parts)));
    }
}
