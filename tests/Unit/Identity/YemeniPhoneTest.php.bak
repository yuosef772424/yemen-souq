<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Support\YemeniPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class YemeniPhoneTest extends TestCase
{
    #[Test]
    #[DataProvider('validNumbers')]
    public function it_normalizes_every_written_form_to_one_value(string $input): void
    {
        $this->assertSame('+967771234567', YemeniPhone::normalize($input));
    }

    public static function validNumbers(): array
    {
        return [
            'محلي بصفر' => ['0771234567'],
            'بلا صفر' => ['771234567'],
            'دولي بعلامة' => ['+967771234567'],
            'دولي بصفرين' => ['00967771234567'],
            'بمسافات وشرطات' => ['0 77-123 4567'],
            'بأرقام عربية' => ['٠٧٧١٢٣٤٥٦٧'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNumbers')]
    public function it_rejects_what_is_not_a_yemeni_mobile(?string $input): void
    {
        $this->assertNull(YemeniPhone::normalize($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'فارغ' => [''],
            'null' => [null],
            'قصير' => ['07712345'],
            'طويل' => ['07712345678'],
            'أرضي' => ['012345678'],
            'بادئة غير عاملة' => ['0751234567'],
            'نص' => ['رقمي عندك'],
        ];
    }

    #[Test]
    public function it_formats_back_for_display(): void
    {
        $this->assertSame('0771234567', YemeniPhone::forDisplay('+967771234567'));
    }
}