<?php

namespace App\Modules\Search\Services;

use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Shared\Services\ArabicNormalizer;

/**
 * تشغيل المجموعة الذهبية وإخراج رقم واحد لجودة البحث.
 *
 * ═══ لماذا هذا أهم ملف في وحدة البحث ═══
 *
 * كل ما سبق — المكافئات، النقل، التراجع، الترتيب — تحسينات مزعومة.
 * بلا مقياس، «حسّنت البحث» جملة لا تُفحص، والتغيير التالي قد يُتلف ما
 * أصلحه السابق بلا أن يلاحظه أحد. ومع مقياس، تصبح الجملة: «التغطية
 * 8/10 صارت 10/10 وصفر مخالفة» — وهذا ما يمكن قوله في مقابلة.
 *
 * الحالة التي لا يوجد لها إعلان تُعلَّم «متروكة»: غياب البيانات ليس
 * عيباً في المحرك، وحسابها ساقطةً يجعل الرقم كاذباً في الاتجاه المتشائم
 * — وهو كذب أيضاً.
 */
final class SearchEvaluator
{
    public function __construct(
        private readonly SearchQueryBuilder $search,
        private readonly ArabicNormalizer $normalizer,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $cases
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     passed: int, failed: int, skipped: int, total: int,
     *     violations: int
     * }
     */
    public function run(array $cases): array
    {
        $results = [];
        $passed = $failed = $skipped = $violations = 0;

        foreach ($cases as $case) {
            $outcome = $this->evaluate($case);

            $results[] = $outcome;

            match ($outcome['status']) {
                'pass' => $passed++,
                'skip' => $skipped++,
                default => $failed++,
            };

            $violations += $outcome['violation'] ? 1 : 0;
        }

        return [
            'results' => $results,
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
            'total' => count($cases),
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function evaluate(array $case): array
    {
        $found = $this->search->search(new SearchCriteria(
            query: (string) $case['query'],
            perPage: 24,
        ));

        $titles = array_map(
            fn ($listing): string => $this->normalizer->normalize($listing->title),
            $found['listings']->items()
        );

        $report = fn (string $status, string $note, bool $violation = false): array => [
            'name' => $case['name'] ?? $case['query'],
            'query' => $case['query'],
            'status' => $status,
            'note' => $note,
            'mode' => $found['mode'],
            'results' => $found['listings']->total(),
            'violation' => $violation,
        ];

        // مخالفة «يجب ألّا يطابق» أخطر من نقص التغطية: الأولى تعطي المستخدم
        // نتيجة خاطئة، والثانية لا تعطيه شيئاً. لذلك تُفحص أولاً وتُعدّ منفصلة
        foreach ($case['forbid_all'] ?? [] as $forbidden) {
            foreach ($titles as $title) {
                if (str_contains($title, $this->normalizer->normalize($forbidden))) {
                    return $report('fail', "نتيجة ممنوعة تحتوي «{$forbidden}»", violation: true);
                }
            }
        }

        if ($case['expect_zero'] ?? false) {
            return $found['listings']->total() === 0
                ? $report('pass', 'صفر كما هو متوقّع')
                : $report('fail', 'نتائج حيث يجب أن يكون صفر', violation: true);
        }

        $expected = $case['expect_any'] ?? [];

        if ($expected !== [] && ! $this->matchesAny($titles, $expected)) {
            // لا نتيجة *ولا* إعلان يحمل المتوقّع: البيانات ناقصة لا المحرك
            return $this->existsInCatalogue($expected)
                ? $report('fail', 'الإعلان موجود ولم يُعثر عليه')
                : $report('skip', 'لا إعلان يطابق المتوقّع في القاعدة');
        }

        if ($found['listings']->total() < (int) ($case['min_results'] ?? 0)) {
            return $report('fail', 'نتائج أقل من الحد الأدنى');
        }

        $modes = $case['modes'] ?? [];

        if ($modes !== [] && ! in_array($found['mode'], $modes, true)) {
            return $report('fail', 'درجة مطابقة غير متوقّعة');
        }

        return $report('pass', 'مطابق');
    }

    /**
     * @param  array<int, string>  $titles
     * @param  array<int, string>  $expected
     */
    private function matchesAny(array $titles, array $expected): bool
    {
        foreach ($expected as $needle) {
            $needle = $this->normalizer->normalize($needle);

            foreach ($titles as $title) {
                if ($needle !== '' && str_contains($title, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<int, string> $expected */
    private function existsInCatalogue(array $expected): bool
    {
        foreach ($expected as $needle) {
            $needle = $this->normalizer->normalize($needle);

            if ($needle === '') {
                continue;
            }

            $exists = \App\Modules\Catalog\Models\Listing::query()
                ->where('title_normalized', 'like', "%{$needle}%")
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }
}