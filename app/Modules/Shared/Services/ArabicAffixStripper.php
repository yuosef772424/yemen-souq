<?php

namespace App\Modules\Shared\Services;

/**
 * تجريد الكلمة العربية من سوابقها ولواحقها الشائعة.
 *
 * ═══ لماذا وُجد هذا الصنف ═══
 *
 * البحث كان يطابق بـLIKE '%كلمة%'، وحرف البدل البادئ يمنع أي فهرس —
 * فكل بحث مسحٌ كامل للجدول. القياس على 1.3 مليون صف: 3,580 مللي ثانية
 * لصفحة واحدة، و5,424 لعدّ الترقيم.
 *
 * والبديل الطبيعي MATCH على فهرس FULLTEXT: 13 مللي ثانية و0.5. لكنه
 * يطابق **كلمات كاملة**، والعربية تُلصق سوابقها بالكلمة — فالبحث عن
 * «سياره» لا يجد «بالسياره». وهذا بالضبط سبب اختيار LIKE أصلاً، وهو
 * مكتوب في تعليق SearchQueryBuilder.
 *
 * فالمخرج أن تُخزَّن الصيغة المجرَّدة **بجانب** الأصلية في النص المجمَّع:
 * «بالسياره» تُفهرَس ومعها «سياره». عندها يجد MATCH ما كان يجده LIKE،
 * وتبقى السرعة.
 *
 * ═══ لماذا تجريد لا جذر ═══
 *
 * الجذر العربي («س ي ر») يجمع «سيارة» و«مسيرة» و«سيرة» في جذر واحد —
 * وهي كلمات لا يقصد الباحث أن تتقابل. والتجريد الحرفي للسوابق يحلّ
 * المشكلة العملية (أل التعريف وحروف الجر الملتصقة) بلا هذا الخلط.
 *
 * ═══ الحدّ الأدنى ثلاثة أحرف ═══
 *
 * بلا حدّ يصير «بها» ← «ها» و«وله» ← «له»، وهي جذاذات لا كلمات تُنتج
 * مطابقات عشوائية. والثلاثة هي أقصر كلمة عربية ذات معنى مستقل.
 */
final class ArabicAffixStripper
{
    /**
     * السوابق مرتَّبة من الأطول إلى الأقصر — والترتيب ملزم.
     *
     * «بال» يجب أن تُجرَّب قبل «ب»، وإلا أُزيلت الباء وحدها من
     * «بالسياره» فبقيت «السياره» ولم تلتقِ بـ«سياره».
     */
    private const PREFIXES = [
        'وبال', 'فبال', 'وكال', 'بال', 'كال', 'فال', 'وال', 'لل',
        'ال', 'وب', 'ول', 'وك', 'فب', 'فل', 'ب', 'ل', 'و', 'ف', 'ك',
    ];

    /**
     * اللواحق: الجمع والتأنيث والضمائر المتصلة.
     *
     * ولا تُزال «ه» المفردة: «شهر» و«جهه» و«وجه» تفقد معناها، والمكسب
     * منها أقل من الضرر.
     */
    private const SUFFIXES = [
        'هما', 'كما', 'هم', 'هن', 'كم', 'كن', 'نا', 'ها', 'ين', 'ون', 'ات', 'ان', 'ي',
    ];

    private const MIN_LENGTH = 3;

    /**
     * الصيغة المجرَّدة، أو null إن لم يتغيّر شيء.
     *
     * الكلمة تُعامَل كما هي — يُفترض أنها مرَّت بـArabicNormalizer قبل
     * الوصول إلى هنا، فلا تشكيل ولا همزات مختلفة.
     */
    public function bare(string $word): ?string
    {
        $bare = $this->stripPrefix($word);
        $bare = $this->stripSuffix($bare);

        return $bare === $word ? null : $bare;
    }

    /**
     * الكلمات ومجرَّداتها معاً، بلا تكرار.
     *
     * تُستعمل في موضعين: بناء النص المجمَّع وقت الفهرسة، وتوسيع كلمات
     * الاستعلام وقت البحث. والموضعان لازمان معاً — الأول يجعل «سياره»
     * تجد «بالسياره»، والثاني يجعل «بالسياره» تجد «سياره».
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public function expand(array $words): array
    {
        $out = [];

        foreach ($words as $word) {
            $out[$word] = true;

            $bare = $this->bare($word);

            if ($bare !== null) {
                $out[$bare] = true;
            }
        }

        return array_keys($out);
    }

    private function stripPrefix(string $word): string
    {
        foreach (self::PREFIXES as $prefix) {
            if (! str_starts_with($word, $prefix)) {
                continue;
            }

            $rest = mb_substr($word, mb_strlen($prefix, 'UTF-8'), null, 'UTF-8');

            if (mb_strlen($rest, 'UTF-8') >= self::MIN_LENGTH) {
                return $rest;
            }
        }

        return $word;
    }

    private function stripSuffix(string $word): string
    {
        foreach (self::SUFFIXES as $suffix) {
            if (! str_ends_with($word, $suffix)) {
                continue;
            }

            $rest = mb_substr($word, 0, -mb_strlen($suffix, 'UTF-8'), 'UTF-8');

            if (mb_strlen($rest, 'UTF-8') >= self::MIN_LENGTH) {
                return $rest;
            }
        }

        return $word;
    }
}
