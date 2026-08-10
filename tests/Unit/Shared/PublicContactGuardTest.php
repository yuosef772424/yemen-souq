<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Services\PublicContactGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * حارس النص العام.
 *
 * الحالات السلبية هنا أهم من الموجبة: القاعدة الساذجة «سبعة أرقام
 * متتالية = هاتف» ترفض كل سؤال عن السعر في اليمن، لأن السعر بالريال
 * اليمني يبلغ سبعة وثمانية أرقام بسهولة. ولهذا نطابق شكل الجوال لا
 * طول الأرقام.
 */
class PublicContactGuardTest extends TestCase
{
    private PublicContactGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new PublicContactGuard;
    }

    /** @return array<string, array{0: string}> */
    public static function phoneTexts(): array
    {
        return [
            'رقم محلي' => ['تواصل معي 771234567'],
            'أرقام عربية' => ['رقمي ٧٧١٢٣٤٥٦٧'],
            'مفرَّق بمسافات' => ['رقمي ٧٧١ ٢٣٤ ٥٦٧'],
            'مفرَّق بشرطات' => ['الرقم 771-234-567'],
            'مفرَّق بنقاط' => ['واتساب 771.234.567'],
            'رمز الدولة' => ['واتساب +967771234567'],
            'رمز الدولة بصفرين' => ['اتصل 00967 73 123 456'],
        ];
    }

    #[Test]
    #[DataProvider('phoneTexts')]
    public function it_detects_a_phone_number(string $text): void
    {
        $this->assertTrue($this->guard->containsPhoneNumber($text));
    }

    /** @return array<string, array{0: string}> */
    public static function safeTexts(): array
    {
        return [
            'سؤال عادي' => ['كم السعر النهائي؟ وهل الفحص مسموح؟'],
            'سعر بسبعة أرقام' => ['السعر 4500000 ريال قابل للتفاوض'],
            'سعر بثمانية أرقام' => ['المطلوب 12500000 ريال يمني'],
            'ممشى وموديل' => ['ماشية 120000 كم وموديل 2018'],
            'رقم شاسي طويل' => ['رقم الشاسي 7123456789012'],
            'أرقام مفصولة بكلمات' => ['دفعت 123 ألف وبقي 456 ألف'],
            'فارغ' => [''],
        ];
    }

    #[Test]
    #[DataProvider('safeTexts')]
    public function it_leaves_ordinary_text_alone(string $text): void
    {
        $this->assertFalse($this->guard->containsPhoneNumber($text));
    }

    #[Test]
    public function null_is_not_a_phone_number(): void
    {
        $this->assertFalse($this->guard->containsPhoneNumber(null));
    }
}