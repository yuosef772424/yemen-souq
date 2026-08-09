<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Services\ArabicNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ArabicNormalizerTest extends TestCase
{
    private ArabicNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ArabicNormalizer;
    }

    #[Test]
    #[DataProvider('hamzaVariants')]
    public function it_treats_all_alif_forms_as_identical(string $input): void
    {
        $this->assertSame('اعلانات', $this->normalizer->normalize($input));
    }

    public static function hamzaVariants(): array
    {
        return [
            'ألف عادية' => ['اعلانات'],
            'همزة فوق' => ['أعلانات'],
            'همزة تحت' => ['إعلانات'],
            'ألف ممدودة' => ['آعلانات'],
        ];
    }

    #[Test]
    public function it_converts_taa_marbuta_to_haa(): void
    {
        $this->assertSame(
            $this->normalizer->normalize('سياره'),
            $this->normalizer->normalize('سيارة')
        );
    }

    #[Test]
    public function it_converts_alif_maqsura_to_yaa(): void
    {
        $this->assertSame(
            $this->normalizer->normalize('مستوي'),
            $this->normalizer->normalize('مستوى')
        );
    }

    #[Test]
    public function it_strips_diacritics(): void
    {
        $this->assertSame('كتاب', $this->normalizer->normalize('كِتَابٌ'));
    }

    #[Test]
    public function it_strips_tatweel(): void
    {
        $this->assertSame('جميل', $this->normalizer->normalize('جـــميـل'));
    }

    #[Test]
    public function it_converts_arabic_digits_to_latin(): void
    {
        $this->assertSame('سياره موديل 2020', $this->normalizer->normalize('سياره موديل ٢٠٢٠'));
    }

    #[Test]
    public function it_collapses_repeated_whitespace(): void
    {
        $this->assertSame('بيت للبيع', $this->normalizer->normalize("  بيت    للبيع \n "));
    }

    #[Test]
    public function it_replaces_punctuation_with_space(): void
    {
        $this->assertSame('سياره تويوتا 2015', $this->normalizer->normalize('سيارة، تويوتا - 2015!'));
    }

    #[Test]
    public function it_returns_empty_string_for_null_or_blank(): void
    {
        $this->assertSame('', $this->normalizer->normalize(null));
        $this->assertSame('', $this->normalizer->normalize('   '));
    }

    #[Test]
    public function it_preserves_latin_text(): void
    {
        $this->assertSame('iphone 15 pro', $this->normalizer->normalize('iPhone 15 Pro'));
    }

    #[Test]
    public function it_tokenizes_and_drops_single_letter_tokens(): void
    {
        $tokens = $this->normalizer->tokenize('شقة و صنعاء للايجار');

        $this->assertContains('شقه', $tokens);
        $this->assertContains('صنعاء', $tokens);
        $this->assertNotContains('و', $tokens, 'الحروف المفردة تُستبعد');
    }

    #[Test]
    public function it_removes_duplicate_tokens(): void
    {
        $tokens = $this->normalizer->tokenize('سيارة سياره سيارة');

        $this->assertCount(1, $tokens);
    }

    #[Test]
    public function it_builds_a_combined_blob(): void
    {
        $blob = $this->normalizer->buildBlob('تويوتا كورولا', 'سيارات', 'صنعاء');

        $this->assertSame('تويوتا كورولا سيارات صنعاء', $blob);
    }
}
