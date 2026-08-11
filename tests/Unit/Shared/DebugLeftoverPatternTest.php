<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Support\HealthCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * نمط كشف أوامر التصحيح.
 *
 * اختبار وحدة بلا قاعدة بيانات: النمط نصّ، وفحصه لا يحتاج تطبيقاً.
 *
 * وسبب وجوده أن العيب الذي أُصلح كان إنذاراً كاذباً — والإنذار الكاذب
 * لا يُسقط اختباراً ولا يظهر في سجل، بل يظهر كخمسة أسماء ملفات في شاشة
 * يقرأها إنسان ويصدّقها. لا يحرسه إلا هذا.
 */
class DebugLeftoverPatternTest extends TestCase
{
    public static function leftovers(): array
    {
        return [
            'استدعاء مباشر' => ['        dd($listing);'],
            'مع مسافة' => ['        dd ($listing);'],
            'dump' => ['        dump($rows);'],
            'var_dump' => ['        var_dump($payload);'],
            'ray' => ['        ray($user);'],
            'على استعلام' => ['        $query->dump();'],
            'في بداية سطر' => ['dd();'],
        ];
    }

    public static function innocent(): array
    {
        return [
            // هذه بالضبط ما كانت تُبلَّغ خطأً
            'in_array' => ['        return in_array($status, $allowed, true);'],
            'array' => ['        $rows = array();'],
            'add' => ['        $this->add($name, $status, $detail);'],
            'addSelect' => ['        $query->addSelect([$sub]);'],
            'addDays' => ['        $date = now()->addDays(30);'],
            'اسم عمود' => ["        \$table->string('address');"],
            'كلمة عربية بجانب قوس' => ['        // يُضاف(هنا)'],
        ];
    }

    #[Test]
    #[DataProvider('leftovers')]
    public function it_flags_a_real_debug_call(string $line): void
    {
        $this->assertSame(1, preg_match(HealthCheck::DEBUG_PATTERN, $line), $line);
    }

    #[Test]
    #[DataProvider('innocent')]
    public function it_ignores_ordinary_code(string $line): void
    {
        $this->assertSame(0, preg_match(HealthCheck::DEBUG_PATTERN, $line), $line);
    }
}
