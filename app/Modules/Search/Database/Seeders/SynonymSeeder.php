<?php

namespace App\Modules\Search\Database\Seeders;

use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * مكافئات اللهجة اليمنية وأسماء الماركات.
 *
 * القاعدة الحاكمة، وهي نفس قاعدة كلمات الوقف: المكافئ يجمع كلمتين
 * تعنيان الشيء نفسه، لا كلمتين تظهران معاً.
 *
 * ما رُفض إدخاله بقصد:
 *   · بيع ↔ ايجار        عكسان لا مكافئان؛ خطأ فادح في العقار
 *   · وكاله ↔ جديد       «وكالة» قريب من الجديد، لا جديداً
 *   · سياره ↔ باص        فئتان مختلفتان من المشترين
 *   · شقه ↔ بيت          سعران مختلفان ونيّتان مختلفتان
 *
 * هذه الأزواج الأربعة تعيش الآن في SynonymGate::FORBIDDEN، فمن يقترحها
 * لاحقاً — بشراً كان أو نموذجاً — يُرفض آلياً.
 */
class SynonymSeeder extends Seeder
{
    /**
     * مكافئات لهجية: كلمتان عربيتان لنفس الشيء.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const DIALECT = [
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
        ['محل', 'دكان'],
        ['كاميرا', 'كامرة'],
        ['قطع غيار', 'سبيرات'],
    ];

    /**
     * أسماء الماركات بالعربية واللاتينية.
     *
     * ═══ لماذا هذه في جدول لا في LatinArabicTransliterator ═══
     *
     * «ايفون ↔ iphone» نقل *كلمة* لا حرف: لا قاعدة تولّده — الياء في
     * «هيونداي» ليست y ولا i بانتظام، و«شاومي» ليست نقلاً حرفياً
     * لـxiaomi بأي نظام. فهي بيانات محدودة تُدخَل وتُراجَع، لا قاعدة
     * تُطبَّق. والصنف الحتمي يبقى حتمياً: ثلاثون سطراً واختبار وحدة.
     *
     * والفائدة كبيرة رغم قلّة العدد: من يكتب «ايفون» يجد من كتب
     * «iPhone»، وهذان أكثر شكلين لكتابة أكثر جوال يُباع.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const BRANDS = [
        ['ايفون', 'iphone'],
        ['ايفون', 'apple'],
        ['سامسونج', 'samsung'],
        ['جالكسي', 'galaxy'],
        ['هواوي', 'huawei'],
        ['شاومي', 'xiaomi'],
        ['ريدمي', 'redmi'],
        ['انفينكس', 'infinix'],
        ['تكنو', 'tecno'],
        ['تويوتا', 'toyota'],
        ['هيونداي', 'hyundai'],
        ['هونداي', 'hyundai'],
        ['كيا', 'kia'],
        ['نيسان', 'nissan'],
        ['ميتسوبيشي', 'mitsubishi'],
        ['مرسيدس', 'mercedes'],
        ['لكزس', 'lexus'],
        ['ديل', 'dell'],
        ['لينوفو', 'lenovo'],
        ['اتش بي', 'hp'],
        ['ماك بوك', 'macbook'],
        ['نوت', 'note'],
        ['برو', 'pro'],
    ];

    public function run(): void
    {
        $normalizer = app(ArabicNormalizer::class);
        $rows = [];

        foreach ([...self::DIALECT, ...self::BRANDS] as [$term, $synonym]) {
            $term = $normalizer->normalize($term);
            $synonym = $normalizer->normalize($synonym);

            if ($term === '' || $synonym === '' || $term === $synonym) {
                continue;
            }

            // المفتاح الفريد (term, synonym)، والعلاقة تُقرأ متبادلة
            // ومتعدّية في SynonymExpander — فصف واحد لكل زوج يكفي
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