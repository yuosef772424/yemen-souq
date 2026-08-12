<?php

namespace App\Modules\Sms\Console;

use App\Modules\Sms\Services\SmsOutbox;
use Illuminate\Console\Command;

/**
 * استعادة السحبات الميتة ووسم ما فات أجله.
 *
 * ═══ ولماذا يوجد وقد استُدعي في كل سحب ═══
 *
 * `SmsOutbox::claim` ينادي `reap()` أولاً — فما دام الهاتف يعمل يجري
 * التنظيف بلا مجدول. وهذا الأمر لأجل الحالة المعاكسة تحديداً: **الهاتف
 * توقّف**. حينها لا سحبَ ولا تنظيف، وتبقى رسائل عالقة في `claimed`
 * ورسائلُ فات أجلها في `queued` — فيقرأ من يفتح الجدول طابوراً حيّاً
 * وهو ميت.
 *
 * أي أن الأمر ليس تكراراً بل الفرع الآخر: التنظيف عند الاستعمال يخدم
 * التشغيل، والمجدول يخدم التشخيص.
 *
 * جدولته:  Schedule::command('sms:reap')->everyMinute();
 */
class ReapSmsCommand extends Command
{
    protected $signature = 'sms:reap';

    protected $description = 'استعادة سحبات الرسائل الميتة ووسم ما انتهى أجله';

    public function handle(SmsOutbox $outbox): int
    {
        $result = $outbox->reap();

        $this->table(
            ['أُعيدت للطابور', 'استُنفدت', 'انتهى أجلها'],
            [[$result['reclaimed'], $result['exhausted'], $result['expired']]],
        );

        return self::SUCCESS;
    }
}
