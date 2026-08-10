<?php

namespace App\Modules\Shared\Services;

/**
 * توليد الصور البديلة لأكواد الموديلات المكتوبة بأسماء الحروف.
 *
 * ═══ لماذا هذا ليس «مكافئات» ═══
 *
 * «اس22» و«s22» ليسا كلمتين مترادفتين — هما كتابتان لنفس السلسلة. الفرق
 * عملي لا لفظي: المكافئ علاقة بين كلمتين محدودتين فيصلح لها جدول، أما
 * أكواد الموديلات ففضاء لا نهائي (s22، s23، a54، m31، …) وينقص كل يوم
 * موديلٌ صدر أمس. ألف صف في جدول لا يُغني عن ثلاثين سطر قاعدة.
 *
 * وهو في الوقت نفسه قاعديّ بالكامل: أسماء الحروف اللاتينية بالعربية
 * مجموعة مغلقة من ثلاثين مدخلاً. فالتحقق منه اختبار وحدة بجدول أزواج،
 * بلا حكم بشري ولا خدمة خارجية.
 *
 * ═══ لماذا في Shared لا في Search ═══
 *
 * لأن الفهرسة هي مستهلكه الأول: ListingBlobBuilder يعيش في Catalog،
 * ولو استورد صنفاً من Search لانقلب اتجاه الاعتماد — وCatalog يملك
 * العمود وSearch يستهلكه، لا العكس. وShared هو بيت ArabicNormalizer
 * لنفس السبب بالضبط.
 *
 * ═══ لماذا التوحيد إلى اللاتينية على الطرفين ═══
 *
 * البديل: خزّن الصورتين وابحث بواحدة. مرفوض لأن العنوان المكتوب
 * «Samsung S22» لا يحمل صورة عربية، فبحث «اس22» يفوّته. التوحيد إلى
 * صورة واحدة يجعل الطرفين يلتقيان حيث ما كُتب أصلاً.
 *
 * والصورة الأصلية تبقى في النص المجمّع كما هي — هذه إضافة لا استبدال.
 */
final class LatinArabicTransliterator
{
    /**
     * اسم الحرف بالعربية ← الحروف اللاتينية التي قد يقصدها.
     *
     * الغموض مقصود ومعالَج بالتعدّد لا بالاختيار: «بي» تُكتب للـb
     * والـp، فلو اخترنا واحداً لفشل نصف الحالات بصمت. إصدار الصورتين
     * يكلّف شرط LIKE إضافياً ويكسب هواوي بي30 وسامسونج بي…
     *
     * ما استُثني بقصد: «اي» وحدها (a/e/i معاً — غموض ثلاثي بلا قيمة
     * تمييزية)، و«ال» (تلتبس بأداة التعريف في كل كلمة عربية تقريباً).
     */
    private const LETTER_NAMES = [
        'ايه' => ['a'],
        'بي' => ['b', 'p'],
        'سي' => ['c'],
        'دي' => ['d'],
        'اف' => ['f'],
        'جي' => ['g', 'j'],
        'اتش' => ['h'],
        'اش' => ['h'],
        'كيه' => ['k'],
        'كي' => ['k'],
        'ام' => ['m'],
        'ان' => ['n'],
        'او' => ['o'],
        'كيو' => ['q'],
        'ار' => ['r'],
        'اس' => ['s'],
        'تي' => ['t'],
        'يو' => ['u'],
        'في' => ['v'],
        'دبليو' => ['w'],
        'اكس' => ['x'],
        'واي' => ['y'],
        'زد' => ['z'],
        'زي' => ['z'],
    ];

    /**
     * أسماء تلتبس بكلمات عربية شائعة.
     *
     * «في» و«او» و«ان» و«ايه» أسماء حروف (v، o، n، a) وكلمات عربية في
     * الوقت نفسه. والفرق الحاسم هو الالتصاق: أحد لا يكتب «في2018»، بينما
     * «اس22» و«ايه54» تُكتبان ملتصقتين دائماً. فهذه الأسماء تُقبل ملتصقة
     * ولا تُقبل مفصولة بفراغ — وبهذا لا يولّد عنوان «بيت في 2020» كلمة
     * v2020 ولا يفقد «جوال ايه54» كلمة a54.
     *
     * البديل المرفوض: حذفها من الخريطة أصلاً. يفقد سلسلة سامسونج A،
     * وهي من أكثر ما يُباع في اليمن — ثمن باهظ لتجنّب كلمة زائدة واحدة.
     */
    private const AMBIGUOUS_WHEN_SPACED = ['في', 'او', 'ان', 'ام', 'كي', 'ايه', 'اش', 'دي'];

    /**
     * سقف عدد الصور المولَّدة لنص واحد.
     *
     * بلا سقف، عنوان فيه ستة أكواد غامضة يولّد عشرات الكلمات في العمود
     * المجمّع — فينتفخ العمود ويصير كل شيء يطابق كل شيء.
     */
    public const MAX_VARIANTS = 12;

    public function __construct(
        private readonly ArabicNormalizer $normalizer,
    ) {
    }

    /**
     * الصور البديلة لنص (عنوان إعلان أو كلمة استعلام).
     *
     * تُرجع الجديد فقط — ما كان في النص أصلاً لا يُعاد.
     *
     * @return array<int, string>
     */
    public function variants(?string $text): array
    {
        $normalized = $this->normalizer->normalize($text);

        if ($normalized === '') {
            return [];
        }

        $found = [
            ...$this->fromLetterNames($normalized, attached: true),
            ...$this->fromLetterNames($normalized, attached: false),
            ...$this->joinWordAndDigits($normalized),
        ];

        $existing = explode(' ', $normalized);

        $new = array_values(array_unique(array_filter(
            $found,
            fn (string $variant): bool => ! in_array($variant, $existing, true),
        )));

        return array_slice($new, 0, self::MAX_VARIANTS);
    }

    /**
     * اسم حرف عربي مع رقم: «اس22» ملتصقاً أو «اس 22» مفصولاً.
     *
     * تُصدَر الصورة اللاتينية (s22) لأنها صورة التوحيد، وتُصدَر العربية
     * الملتصقة (اس22) كذلك لأن من كتب العنوان بفراغ يبحث بلا فراغ.
     *
     * @return array<int, string>
     */
    private function fromLetterNames(string $normalized, bool $attached): array
    {
        $names = $this->namesLongestFirst();

        if (! $attached) {
            $names = array_values(array_diff($names, self::AMBIGUOUS_WHEN_SPACED));
        }

        if ($names === []) {
            return [];
        }

        $alternation = implode('|', array_map('preg_quote', $names));
        $gap = $attached ? '' : '\s';

        if (! preg_match_all(
            "/(?:^|\\s)({$alternation}){$gap}(\\d{1,4})(?=\\s|$)/u",
            $normalized,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $variants = [];

        foreach ($matches as $match) {
            [$name, $digits] = [$match[1], $match[2]];

            foreach (self::LETTER_NAMES[$name] as $letter) {
                $variants[] = $letter . $digits;
            }

            $variants[] = $name . $digits;
        }

        return $variants;
    }

    /**
     * إلصاق كلمة برقم يفصله عنها فراغ: «ايفون 13» و«نوت 20» و«note 20».
     *
     * إلصاق فقط، لا نقل: من كتب العنوان بفراغ ومن يبحث بلا فراغ يقصدان
     * الشيء نفسه، وLIKE يغطي الاتجاه المعاكس وحده (استعلام «ايفون 13»
     * يجد عنوان «ايفون13» لأن المطابقة جزئية، والعكس لا).
     *
     * ونقل «iphone» إلى «ايفون» ليس من عمل هذا الصنف: ذلك نقل *كلمة*
     * لا حرف، ومكانه جدول المكافئات حيث يُراجَع، لا قاعدة عامة.
     *
     * الثمن المعلوم: «كورولا 2018» تولّد «كورولا2018» ولا يبحث عنها أحد.
     * كلمة زائدة واحدة لكل رقم في العنوان مقابل مطابقة مفقودة — والمطابقة
     * المفقودة أغلى.
     *
     * @return array<int, string>
     */
    private function joinWordAndDigits(string $normalized): array
    {
        if (! preg_match_all(
            // حرف لاتيني واحد مقبول («s 22» كود موديل)، وحرف عربي واحد لا:
            // المُطبِّع يُسقط الكلمات المفردة أصلاً، و«ب 2» ليست كوداً
            '/(?:^|\s)([a-z]{1,10}|[\p{Arabic}]{2,10})\s(\d{1,4})(?=\s|$)/u',
            $normalized,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $joined = [];

        foreach ($matches as $match) {
            if (in_array($match[1], self::AMBIGUOUS_WHEN_SPACED, true)) {
                continue;
            }

            $joined[] = $match[1] . $match[2];
        }

        return $joined;
    }

    /** @return array<int, string> */
    private function namesLongestFirst(): array
    {
        $names = array_keys(self::LETTER_NAMES);

        // الأطول أولاً: بلا هذا الترتيب تأكل «كي» بداية «كيو»
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }
}