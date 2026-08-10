<?php

namespace App\Modules\Search\Database\Seeders;

use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * مكافئات اللهجة اليمنية.
 *
 * القاعدة الحاكمة، وهي نفس قاعدة كلمات الوقف: المكافئ يجمع كلمتين
 * تعنيان الشيء نفسه، لا كلمتين تظهران معاً.
 *
 * ما رُفض إدخاله بقصد:
 *   · بيع ↔ ايجار        — عكسان لا مكافئان؛ خطأ فادح في العقار
 *   · وكاله ↔ جديد       — «وكالة» تعني قريب من الجديد، لا جديداً
 *   · سياره ↔ باص        — فئتان مختلفتان من المشترين
 *   · شقه ↔ بيت          — سعران مختلفان ونيّتان مختلفتان
 *
 * الكلمات تُخزَّن مطبّعة بنفس ArabicNormalizer الذي يبني search_blob،
 * وإلا فلن تطابق شيئاً: «سيارة» في الجدول لا تساوي «سياره» في العمود.
 */
class SynonymSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string}> */
    private const PAIRS = [
        ['سيارة', 'عربية'],
        ['سيارة', 'موتر'],
        ['دراجة', 'دباب'],
        ['دباب', 'موتوسيكل'],
        ['جوال', 'موبايل'],
        ['جوال', 'تلفون'],
        ['جوال', 'هاتف'],
        ['لابتوب', 'حاسوب'],
        ['لابتوب', 'كمبيوتر'],
        ['تلفزيون', 'شاشة'],
        ['ثلاجة', 'براد'],
        ['مكيف', 'سبليت'],
        ['عفش', 'أثاث'],
        ['عفش', 'موبيليا'],
        ['أرض', 'قطعة'],
        ['محل', 'دكان'],
        ['مطبخ', 'كبت'],
        ['كاميرا', 'كامرة'],
        ['ساعة', 'ساعات'],
        ['قطع غيار', 'سبيرات'],
    ];

    public function run(): void
    {
        $normalizer = app(ArabicNormalizer::class);
        $rows = [];

        foreach (self::PAIRS as [$term, $synonym]) {
            $term = $normalizer->normalize($term);
            $synonym = $normalizer->normalize($synonym);

            if ($term === '' || $synonym === '' || $term === $synonym) {
                continue;
            }

            // المفتاح الفريد (term, synonym) — والعلاقة تُقرأ متبادلة
            // في SynonymExpander، فصف واحد لكل زوج يكفي
            $rows[$term . '|' . $synonym] = [
                'term' => $term,
                'synonym' => $synonym,
                'weight' => 1,
                'is_active' => true,
            ];
        }

        DB::table('search_synonyms')->upsert(
            array_values($rows),
            ['term', 'synonym'],
            ['weight', 'is_active']
        );
    }
}