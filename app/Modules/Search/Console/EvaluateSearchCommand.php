<?php

namespace App\Modules\Search\Console;

use App\Modules\Search\Services\SearchEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan search:evaluate
 *
 * رقم واحد لجودة البحث. يعود بـFAILURE عند أي سقوط، فيصلح بوابةً في
 * سكربت أو في CI لاحقاً.
 */
class EvaluateSearchCommand extends Command
{
    protected $signature = 'search:evaluate {--details : اعرض كل الحالات لا الساقطة فقط}';

    protected $description = 'قياس جودة البحث على المجموعة الذهبية';

    public function handle(SearchEvaluator $evaluator): int
    {
        $path = base_path('database/data/search-golden-set.php');

        if (! is_file($path)) {
            $this->error("المجموعة الذهبية غير موجودة: {$path}");

            return self::FAILURE;
        }

        $report = $evaluator->run(require $path);

        $this->table(
            ['الحالة', 'الاستعلام', 'النتيجة', 'الدرجة', 'العدد', 'ملاحظة'],
            $this->rows($report['results'])
        );

        $this->newLine();
        $this->line("مطابق: {$report['passed']}/{$report['total']}"
            . "   ساقط: {$report['failed']}"
            . "   متروك: {$report['skipped']}"
            . "   مخالفات «يجب ألّا يطابق»: {$report['violations']}");

        $this->reportZeroResultRate();
        $this->reportPendingCandidates();

        if ($report['failed'] > 0) {
            $this->newLine();
            $this->error('المجموعة الذهبية لم تمرّ.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('المجموعة الذهبية مرّت.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<int, string>>
     */
    private function rows(array $results): array
    {
        $labels = ['pass' => 'مطابق', 'fail' => 'ساقط', 'skip' => 'متروك'];

        $rows = [];

        foreach ($results as $result) {
            if (! $this->option('details') && $result['status'] === 'pass') {
                continue;
            }

            $rows[] = [
                (string) $result['name'],
                (string) $result['query'],
                $labels[$result['status']] ?? $result['status'],
                (string) $result['mode'],
                (string) $result['results'],
                (string) $result['note'],
            ];
        }

        return $rows;
    }

    /**
     * نسبة استعلامات الصفر من الجدول المجمَّع.
     *
     * هذا هو المقياس الذي تُقاس عليه المكافئات فعلاً: كل مكافئ صحيح
     * يُضاف يجب أن ينزل هذا الرقم. وهو من بياناتك لا من رأي أحد.
     */
    private function reportZeroResultRate(): void
    {
        $row = DB::table('search_query_stats')
            ->where('stat_date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('SUM(times_searched) as searches, SUM(zero_result_count) as zeros')
            ->first();

        $searches = (int) ($row->searches ?? 0);

        if ($searches === 0) {
            $this->line('استعلامات الصفر: لا بيانات مجمَّعة — شغّل analytics:rollup');

            return;
        }

        $zeros = (int) ($row->zeros ?? 0);

        $this->line(sprintf(
            'استعلامات الصفر (30 يوماً): %d من %d — %.1f%%',
            $zeros,
            $searches,
            $zeros / $searches * 100
        ));
    }

    private function reportPendingCandidates(): void
    {
        $pending = DB::table('search_synonym_candidates')->where('status', 'pending')->count();

        $this->line("مرشّحات مكافئات بانتظار المراجعة: {$pending}");
    }
}