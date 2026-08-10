<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Services\ArabicNormalizer;
use App\Modules\Shared\Services\LatinArabicTransliterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * القاعدة الحتمية تُختبر بجدول أزواج — بلا قاعدة بيانات وبلا لارافل.
 *
 * وهذا بالضبط سبب تفضيل القاعدة على الجدول لأكواد الموديلات: التحقق
 * كامل هنا، في ملف واحد، بلا حكم بشري ولا خدمة خارجية. الملف يُقرأ
 * كمواصفة: كل سطر حالة كتابة حقيقية ونتيجتها.
 */
class LatinArabicTransliteratorTest extends TestCase
{
    private LatinArabicTransliterator $transliterator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transliterator = new LatinArabicTransliterator(new ArabicNormalizer);
    }

    /** @return array<string, array{0: string, 1: array<int, string>}> */
    public static function cases(): array
    {
        return [
            'اسم حرف ملتصق برقم' => ['سامسونج جالكسي اس22 مستعمل', ['s22']],
            'اسم حرف مفصول بفراغ' => ['سامسونج جالكسي اس 22', ['s22', 'اس22']],
            'حرف لاتيني مفصول' => ['Samsung Galaxy S 22', ['s22']],
            'الصورة اللاتينية موجودة أصلاً' => ['Samsung Galaxy S22', []],
            'اسم غامض يعطي الصورتين' => ['هواوي بي30 برو', ['b30', 'p30']],
            'سلسلة سامسونج A' => ['جوال ايه54 جديد', ['a54']],
            'كلمة عربية مع رقم' => ['ايفون 13 برو ماكس', ['ايفون13']],
            'كلمة لاتينية مع رقم' => ['note 20 ultra', ['note20']],
            'الأطول أولاً — كيو لا كي' => ['اودي كيو7', ['q7']],
            'بلا أرقام لا صور' => ['مرسيدس اس كلاس', []],
            'نص فارغ' => ['', []],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_generates_the_expected_variants(string $text, array $expected): void
    {
        $this->assertSame($expected, $this->transliterator->variants($text));
    }

    /**
     * «في» اسم الحرف v وحرف الجر الأشيع في العربية معاً.
     *
     * الفرق الحاسم هو الالتصاق: أحد لا يكتب «في2018». فلو قُبلت مفصولة
     * لولّد كل عنوان فيه «في <سنة>» كلمة v2020 — ضجيج في العمود المجمّع
     * بلا أي مطابقة يخدمها.
     */
    #[Test]
    public function a_function_word_followed_by_a_number_generates_nothing(): void
    {
        $this->assertSame([], $this->transliterator->variants('بيت للبيع في 2020'));
        $this->assertSame([], $this->transliterator->variants('شقة او 3 غرف'));
    }

    #[Test]
    public function the_number_of_variants_is_capped(): void
    {
        $crowded = 'بي1 جي2 بي3 جي4 بي5 جي6 بي7 جي8';

        $this->assertLessThanOrEqual(
            LatinArabicTransliterator::MAX_VARIANTS,
            count($this->transliterator->variants($crowded))
        );
    }

    #[Test]
    public function arabic_digits_are_handled_like_latin_ones(): void
    {
        $this->assertSame(['s22'], $this->transliterator->variants('جالكسي اس٢٢'));
    }
}