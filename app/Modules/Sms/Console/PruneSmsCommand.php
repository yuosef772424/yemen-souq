<?php

namespace App\Modules\Sms\Console;

use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Console\Command;

/**
 * حذف الرسائل المنتهية.
 *
 * سبعة أيام لا تسعون كما في الإشعارات: الصفّ هنا إيصالُ تسليم، وقيمته
 * تنتهي بانتهاء التحقيق في «لماذا لم تصلني الرسالة أمس». والفاشلة تحمل
 * نصّها كاملاً — أي رمز تحقق صريح — فطولُ الاحتفاظ هنا مخاطرةٌ لا أرشيف.
 *
 * جدولته:  Schedule::command('sms:prune')->daily();
 */
class PruneSmsCommand extends Command
{
    protected $signature = 'sms:prune {--days= : عدد أيام الاحتفاظ}';

    protected $description = 'حذف رسائل الطابور المنتهية (المُرسَلة والفاشلة ومنتهية الأجل)';

    public function handle(SmsOutbox $outbox): int
    {
        $days = $this->option('days');

        $deleted = $outbox->prune($days === null ? null : (int) $days);

        $this->info("حُذف {$deleted} صفاً.");

        return self::SUCCESS;
    }
}
