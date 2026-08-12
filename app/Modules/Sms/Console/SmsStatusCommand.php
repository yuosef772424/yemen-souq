<?php

namespace App\Modules\Sms\Console;

use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Console\Command;

/**
 * حالة الطابور من الطرفية.
 *
 * ═══ الرقم الذي يهمّ هو عمر الأقدم لا العدد ═══
 *
 * طابورٌ فيه ثلاث رسائل أمرٌ عادي. وثلاثٌ أقدمها عمرها ساعة يعني أن
 * الهاتف لم ينادِ منذ ساعة — وهو عطلٌ لا يقوله العدد وحده. ولهذا
 * يُرجع الأمر رمز خروج غير صفري حين يتجاوز العمرُ العتبة: يصلح
 * للمراقبة الآلية بلا تحليل نصّ.
 */
class SmsStatusCommand extends Command
{
    protected $signature = 'sms:status {--stale=300 : عتبة تقادم أقدم رسالة بالثواني}';

    protected $description = 'عرض حالة طابور الرسائل النصية';

    public function handle(SmsOutbox $outbox): int
    {
        if (! (bool) config('sms.enabled', false)) {
            $this->warn('وحدة الرسائل مطفأة (SMS_ENABLED=false) — الطابور لا يُكتب فيه.');
        }

        $stats = $outbox->stats();

        $this->table(
            ['في الطابور', 'مسحوبة', 'أُرسلت', 'فشلت', 'انتهى أجلها'],
            [[$stats['queued'], $stats['claimed'], $stats['sent'], $stats['failed'], $stats['expired']]],
        );

        $this->line('آخر سحب: '.($stats['last_claim_at'] ?? 'لم يحدث بعد'));

        $oldest = $stats['oldest_queued_seconds'];

        if ($oldest === null) {
            $this->info('الطابور فارغ.');

            return self::SUCCESS;
        }

        $threshold = (int) $this->option('stale');

        $this->line("أقدم رسالة في الطابور منذ {$oldest} ثانية.");

        if ($oldest > $threshold) {
            $this->error(
                "الطابور متوقف: لم يسحب أي هاتف منذ أكثر من {$threshold} ثانية. "
                .'افحص العامل على الهاتف — termux-wake-lock والشبكة.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
