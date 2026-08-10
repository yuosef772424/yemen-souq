<?php

namespace App\Modules\Search\Console;

use App\Modules\Search\Services\SearchEvaluator;
use App\Modules\Search\Services\SynonymGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan search:promote-synonyms [--min-evidence=2] [--source=] [--dry-run]
 *
 * الطريق الوحيد من جدول المرشّحين إلى جدول المكافئات الحيّ.
 *
 * ═══ لماذا أمر لا استعلام SQL يدوي ═══
 *
 * لأن الترقية ليست إدخال صف: هي بوابة ثم فحص انحدار ثم تراجع عند الفشل.
 * ومن يُدخل الصف يدوياً يتخطّى الثلاثة. جعلُ الطريق الصحيح أسهل من
 * الخطأ هو ما يجعله يُتبع.
 *
 * التسلسل:
 *   1. المرشّحون المعلَّقون بدليل كافٍ
 *   2. SynonymGate على كل زوج — النقائض والتصنيف والسعر
 *   3. إدخال المقبولين في معاملة واحدة
 *   4. المجموعة الذهبية بعد الإدخال
 *   5. انحدار ⇒ تراجع كامل عن الدفعة، وتُعلَّم مرفوضة بسبب صريح
 *
 * الدفعة ترتفع أو تسقط معاً لا فرداً فرداً: تقسيمها إلى ترقيات مفردة
 * يعني تشغيل المجموعة الذهبية لكل زوج — أبطأ بمراحل، ومع ذلك لا يكشف
 * الزوجين اللذين يُفسدان معاً ولا يُفسد أحدهما وحده.
 */
class PromoteSynonymsCommand extends Command
{
    protected $signature = 'search:promote-synonyms
                            {--min-evidence=2 : أدنى عدد جلسات مستقلة}
                            {--source= : rule|behaviour|ai|manual}
                            {--dry-run : افحص بلا كتابة}';

    protected $description = 'ترقية مرشّحات المكافئات عبر البوابة والمجموعة الذهبية';

    public function handle(SynonymGate $gate, SearchEvaluator $evaluator): int
    {
        $candidates = $this->pending();

        if ($candidates->isEmpty()) {
            $this->info('لا مرشّحين مطابقين للشرط.');

            return self::SUCCESS;
        }

        $goldenSetPath = base_path('database/data/search-golden-set.php');
        $before = is_file($goldenSetPath) ? $evaluator->run(require $goldenSetPath) : null;

        [$approved, $rows] = $this->assessAll($gate, $candidates);

        if ($this->option('dry-run')) {
            $this->table(['المصطلح', 'المكافئ', 'المصدر', 'الحكم', 'السبب'], $rows);
            $this->info('فحص فقط — لم يُكتب شيء.');

            return self::SUCCESS;
        }

        $this->table(['المصطلح', 'المكافئ', 'المصدر', 'الحكم', 'السبب'], $rows);

        if ($approved === []) {
            $this->warn('لم يعبر أي مرشّح البوابة.');

            return self::SUCCESS;
        }

        $this->insert($approved);

        if ($before !== null && $this->regressed($before, $evaluator->run(require $goldenSetPath))) {
            $this->rollback($approved);

            $this->error('انحدار في المجموعة الذهبية — تراجعت عن الدفعة كاملة.');

            return self::FAILURE;
        }

        $this->info('رُقِّي ' . count($approved) . ' مكافئاً.');

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function pending()
    {
        return DB::table('search_synonym_candidates')
            ->where('status', 'pending')
            ->where('evidence_count', '>=', (int) $this->option('min-evidence'))
            ->when($this->option('source'), fn ($q, $source) => $q->where('source', $source))
            ->orderByDesc('evidence_count')
            ->get();
    }

    /**
     * @return array{0: array<int, object>, 1: array<int, array<int, string>>}
     */
    private function assessAll(SynonymGate $gate, $candidates): array
    {
        $approved = [];
        $rows = [];

        foreach ($candidates as $candidate) {
            $verdict = $gate->assess($candidate->term, $candidate->synonym);

            $rows[] = [
                $candidate->term,
                $candidate->synonym,
                $candidate->source,
                match (true) {
                    $verdict->passed() => 'مقبول',
                    $verdict->rejected() => 'مرفوض',
                    default => 'غير محقَّق',
                },
                $verdict->reason(),
            ];

            if ($verdict->passed()) {
                $approved[] = $candidate;

                continue;
            }

            if ($this->option('dry-run')) {
                continue;
            }

            // «غير محقَّق» يبقى معلَّقاً لا مرفوضاً: نقص البيانات ليس حكماً،
            // وقد تكفي بيانات الشهر القادم للفصل فيه
            if ($verdict->rejected()) {
                $this->decide($candidate->id, 'rejected', $verdict->reason());
            }
        }

        return [$approved, $rows];
    }

    /** @param array<int, object> $approved */
    private function insert(array $approved): void
    {
        DB::transaction(function () use ($approved): void {
            foreach ($approved as $candidate) {
                DB::table('search_synonyms')->upsert(
                    [[
                        'term' => $candidate->term,
                        'synonym' => $candidate->synonym,
                        'weight' => 1,
                        'is_active' => true,
                    ]],
                    ['term', 'synonym'],
                    ['is_active']
                );

                $this->decide($candidate->id, 'promoted', 'عبر البوابة والمجموعة الذهبية');
            }
        });
    }

    /** @param array<int, object> $approved */
    private function rollback(array $approved): void
    {
        DB::transaction(function () use ($approved): void {
            foreach ($approved as $candidate) {
                DB::table('search_synonyms')
                    ->where('term', $candidate->term)
                    ->where('synonym', $candidate->synonym)
                    ->delete();

                $this->decide($candidate->id, 'rejected', 'انحدار في المجموعة الذهبية');
            }
        });
    }

    private function decide(int $id, string $status, string $reason): void
    {
        DB::table('search_synonym_candidates')->where('id', $id)->update([
            'status' => $status,
            'decision_reason' => mb_substr($reason, 0, 255),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * الانحدار: حالة كانت تمرّ صارت تسقط، أو ظهرت مخالفة جديدة.
     *
     * ولا يُعدّ انحداراً أن تزيد الحالات المتروكة: ذلك يعني أن الدفعة
     * لم تلمس بيانات ناقصة، وهو حياد لا تراجع.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function regressed(array $before, array $after): bool
    {
        return $after['failed'] > $before['failed']
            || $after['violations'] > $before['violations'];
    }
}