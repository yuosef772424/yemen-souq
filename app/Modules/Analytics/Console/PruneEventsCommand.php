<?php

namespace App\Modules\Analytics\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * حذف السجل الخام بعد مدة الاحتفاظ.
 *
 * الجداول المجمّعة تبقى للأبد وهي كافية لكل التحليلات.
 * الاحتفاظ بالخام إلى الأبد يعني نمواً بلا حد وخطر خصوصية بلا مقابل.
 */
class PruneEventsCommand extends Command
{
    protected $signature = 'analytics:prune {--days=90 : مدة الاحتفاظ بالأيام}';

    protected $description = 'حذف أحداث السلوك الأقدم من مدة الاحتفاظ';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $total = 0;

        // الحذف بدفعات: DELETE واحد لملايين الصفوف يقفل الجدول
        do {
            $deleted = DB::table('user_events')
                ->where('occurred_at', '<', $cutoff)
                ->limit(5000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        $this->components->info("تم حذف {$total} حدثاً أقدم من {$days} يوماً.");

        return self::SUCCESS;
    }
}
