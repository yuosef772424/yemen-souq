<?php

namespace App\Modules\Notifications\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * حذف الإشعارات بعد مدة الاحتفاظ.
 *
 * الجدول ينمو بمعدّل كل رسالة وكل سؤال في المنصة، ولا يُقرأ منه إلا
 * الصفحة الأولى. إشعارٌ عمرُه تسعون يوماً لن يُفتح — والصفوف المقروءة
 * انتهى دورها يوم قُرئت.
 *
 * الشرط على created_at لا على read_at بقصد: غير المقروء بعد تسعين يوماً
 * يُحذف أيضاً. البديل — إبقاء غير المقروء إلى الأبد — يعني أن حساباً
 * مهجوراً يحمل جدوله إلى ما لا نهاية، وهو أكثر الحسابات إنتاجاً لغير
 * المقروء.
 */
class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune {--days= : مدة الاحتفاظ بالأيام}';

    protected $description = 'حذف الإشعارات الأقدم من مدة الاحتفاظ';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('notifications.retention_days', 90));
        $days = max(7, $days);

        $cutoff = now()->subDays($days);

        $total = 0;

        // الحذف بدفعات — بنفس حجّة analytics:prune
        do {
            $deleted = DB::table('app_notifications')
                ->where('created_at', '<', $cutoff)
                ->limit(5000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        $this->components->info("تم حذف {$total} إشعاراً أقدم من {$days} يوماً.");

        return self::SUCCESS;
    }
}
