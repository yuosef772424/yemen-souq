<?php

namespace App\Modules\Search\Services;

use App\Modules\Search\DTO\GateVerdict;
use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * البوابة الآلية بين المرشَّح والجدول الحيّ.
 *
 * ثلاث فحوص، كلها على بياناتك أنت — لا رأي ولا خدمة خارجية:
 *
 *   1. النقائض   قائمة صريحة. أي زوج يلمسها يُرفض بلا نقاش.
 *   2. التصنيف   هل يظهر الطرفان في نفس شجرة التصنيف؟
 *   3. السعر     هل توزيعا سعرهما متقاطعان؟
 *
 * الفحصان الثاني والثالث هما ما يفرّق «براد ↔ ثلاجة» عن «شقة ↔ بيت»:
 * الأولان في نفس التصنيف وبنفس نطاق السعر، والآخران في تصنيفين وبفارق
 * سعري بعشرات الأضعاف. الحكم يخرج من الجدول لا من اللغة.
 *
 * وما لا تفعله هذه البوابة: لا تحكم على ما لا تملك بيانات عنه. الزوج
 * الذي لا يظهر طرفه الثاني في أي إعلان يعود «غير قابل للتحقق» —
 * وقرارُه للبشر، لا للرفض الآلي.
 */
final class SynonymGate
{
    /**
     * أزواج ممنوعة نهائياً.
     *
     * مكتوبة مطبّعة. وهي قائمة *نية* لا قائمة معانٍ: الطرفان يصفان
     * الشيء نفسه أحياناً، لكن من يبحث بأحدهما لا يريد الآخر أبداً.
     * ولهذا لا يمكن لأي فحص إحصائي أن يكتشفها — «شقة للبيع» و«شقة
     * للإيجار» في نفس التصنيف وبنفس الكلمات.
     */
    private const FORBIDDEN = [
        ['بيع', 'ايجار'],
        ['للبيع', 'للايجار'],
        ['مطلوب', 'معروض'],
        ['جديد', 'مستعمل'],
        ['وكاله', 'مستعمل'],
        ['جديد', 'وكاله'],
        ['شقه', 'بيت'],
        ['شقه', 'فيلا'],
        ['ارض', 'بيت'],
        ['بيع', 'شراء'],
    ];

    /** أدنى عدد إعلانات لكل طرف حتى يصح الفحص الإحصائي */
    public const MIN_LISTINGS_TO_JUDGE = 3;

    /** أقصى نسبة بين وسيطَي السعر — أعلى منها يعني سلعتين مختلفتين */
    public const MAX_PRICE_RATIO = 6.0;

    public function __construct(
        private readonly ArabicNormalizer $normalizer,
    ) {
    }

    public function assess(string $term, string $synonym): GateVerdict
    {
        $term = $this->normalizer->normalize($term);
        $synonym = $this->normalizer->normalize($synonym);

        if ($term === '' || $synonym === '' || $term === $synonym) {
            return GateVerdict::reject('طرف فارغ أو الطرفان متطابقان');
        }

        if (mb_strlen($term) < 2 || mb_strlen($synonym) < 2) {
            return GateVerdict::reject('كلمة من حرف واحد لا تُميّز شيئاً');
        }

        if ($this->isForbidden($term, $synonym)) {
            return GateVerdict::reject('زوج من قائمة النقائض');
        }

        $left = $this->profile($term);
        $right = $this->profile($synonym);

        if ($left['count'] < self::MIN_LISTINGS_TO_JUDGE || $right['count'] < self::MIN_LISTINGS_TO_JUDGE) {
            return GateVerdict::unverified('إعلانات غير كافية للحكم الإحصائي');
        }

        if (array_intersect($left['roots'], $right['roots']) === []) {
            return GateVerdict::reject('لا تصنيف جذر مشترك بين الطرفين');
        }

        $ratio = $this->priceRatio($left['median'], $right['median']);

        if ($ratio !== null && $ratio > self::MAX_PRICE_RATIO) {
            return GateVerdict::reject(sprintf('فارق سعري %.1f ضعف', $ratio));
        }

        return GateVerdict::pass([
            sprintf('تصنيف مشترك · %d/%d إعلاناً', $left['count'], $right['count']),
        ]);
    }

    private function isForbidden(string $a, string $b): bool
    {
        foreach (self::FORBIDDEN as [$x, $y]) {
            $x = $this->normalizer->normalize($x);
            $y = $this->normalizer->normalize($y);

            if (($a === $x && $b === $y) || ($a === $y && $b === $x)) {
                return true;
            }
        }

        return false;
    }

    /**
     * بصمة كلمة في الإعلانات: كم إعلاناً، في أي تصنيفات جذر، وبأي سعر وسيط.
     *
     * المطابقة على title_normalized لا على search_blob بقصد: المجمّع يضم
     * اسم التصنيف والمحافظة، فكل إعلان في «سيارات» يحتوي كلمة «سيارات» —
     * فتصبح بصمة الكلمة هي بصمة التصنيف كله ويفقد الفحص معناه.
     *
     * @return array{count: int, roots: array<int, int>, median: ?float}
     */
    private function profile(string $word): array
    {
        $rows = DB::table('listings')
            ->join('categories', 'categories.id', '=', 'listings.category_id')
            ->whereNull('listings.deleted_at')
            ->where('listings.title_normalized', 'like', "%{$word}%")
            ->select('categories.path', 'listings.price_in_base')
            ->limit(500)
            ->get();

        $roots = [];
        $prices = [];

        foreach ($rows as $row) {
            $roots[] = (int) explode('/', (string) $row->path)[0];

            if ($row->price_in_base !== null) {
                $prices[] = (float) $row->price_in_base;
            }
        }

        return [
            'count' => $rows->count(),
            'roots' => array_values(array_unique(array_filter($roots))),
            'median' => $this->median($prices),
        ];
    }

    /**
     * الوسيط لا المتوسط.
     *
     * إعلان واحد بسعر خاطئ (مليار ريال لسهو في الأصفار) يجرّ المتوسط
     * معه فيرفض زوجاً صحيحاً. الوسيط لا يتحرّك.
     *
     * @param  array<int, float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    private function priceRatio(?float $a, ?float $b): ?float
    {
        if ($a === null || $b === null || $a <= 0.0 || $b <= 0.0) {
            return null;
        }

        return max($a, $b) / min($a, $b);
    }
}