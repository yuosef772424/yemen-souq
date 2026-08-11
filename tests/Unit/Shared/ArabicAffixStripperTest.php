<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Services\ArabicAffixStripper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * تجريد السوابق واللواحق.
 *
 * الحالات السلبية هنا أهم من الموجبة: تجريد زائد يجعل «بها» ← «ها»،
 * فتتقابل كلمات لا علاقة بينها — وذلك عيب أسوأ من فوات مطابقة، لأنه
 * يُدخل ضجيجاً في نتائج كل بحث بدل أن يُنقص من نتائج بحث واحد.
 */
class ArabicAffixStripperTest extends TestCase
{
    private ArabicAffixStripper $stripper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripper = new ArabicAffixStripper;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function stripped(): array
    {
        return [
            'أل التعريف' => ['السياره', 'سياره'],
            'الباء وأل' => ['بالسياره', 'سياره'],
            'الواو وأل' => ['والسياره', 'سياره'],
            'الكاف وأل' => ['كالسياره', 'سياره'],
            'اللام المضاعفة' => ['للسياره', 'سياره'],
            'الباء وحدها' => ['بصنعاء', 'صنعاء'],
            'جمع مؤنث' => ['سيارات', 'سيار'],
            'جمع مذكر' => ['مهندسين', 'مهندس'],
            'ضمير متصل' => ['سيارتها', 'سيارت'],
        ];
    }

    #[Test]
    #[DataProvider('stripped')]
    public function it_strips_known_affixes(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->stripper->bare($input));
    }

    /** @return array<string, array{0: string}> */
    public static function untouched(): array
    {
        return [
            'كلمة قصيرة بعد التجريد' => ['بها'],
            'كلمة قصيرة أصلاً' => ['له'],
            'بلا سابقة' => ['صنعاء'],
            'مجرَّدة أصلاً' => ['سياره'],
            'حرفان' => ['في'],
        ];
    }

    #[Test]
    #[DataProvider('untouched')]
    public function it_leaves_short_or_bare_words_alone(string $word): void
    {
        $bare = $this->stripper->bare($word);

        $this->assertTrue(
            $bare === null || mb_strlen($bare) >= 3,
            "«{$word}» جُرِّد إلى «{$bare}» وهو أقصر من ثلاثة أحرف."
        );
    }

    /**
     * الترتيب من الأطول إلى الأقصر ملزم.
     *
     * لو جُرّبت «ب» قبل «بال» لبقيت «السياره» — فلا تلتقي بـ«سياره».
     */
    #[Test]
    public function the_longest_prefix_wins(): void
    {
        $this->assertSame('سياره', $this->stripper->bare('بالسياره'));
    }

    #[Test]
    public function expand_keeps_the_original_beside_the_bare_form(): void
    {
        $result = $this->stripper->expand(['بالسياره', 'صنعاء']);

        $this->assertContains('بالسياره', $result);
        $this->assertContains('سياره', $result);
        $this->assertContains('صنعاء', $result);
    }

    #[Test]
    public function expand_does_not_repeat_a_word(): void
    {
        $result = $this->stripper->expand(['سياره', 'سياره']);

        $this->assertSame(['سياره'], $result);
    }
}
