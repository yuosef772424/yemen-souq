<?php

namespace App\Modules\Sms\Console;

use App\Modules\Sms\Enums\DeliveryChannel;
use App\Modules\Sms\Enums\SmsStatus;
use App\Modules\Sms\Jobs\DeliverWhatsApp;
use App\Modules\Sms\Models\SmsMessage;
use Illuminate\Console\Command;

/**
 * شبكة الأمان للقنوات التي يدفعها الخادم.
 *
 * ═══ لماذا يوجد وقد دُفعت المهمّة عند الإضافة ═══
 *
 * `SmsOutbox::queue` يدفع `DeliverWhatsApp` فوراً. وهذا يكفي حين يعمل
 * عامل الطابور. وهو **لا يعمل دائماً على استضافة مشتركة**: لا عملية
 * دائمة، و`queue:work` يُشغَّل من cron فيموت بين تشغيلين، ومهمّةٌ دُفعت
 * أثناء ذلك تنتظر.
 *
 * فهذا الأمر يمرّ على ما بقي `queued` ويعيد دفعه. والنتيجة أن الحدّ
 * الأدنى للتشغيل صار **سطر cron واحداً** لا عامل طابور دائم:
 *
 *   Schedule::command('sms:dispatch')->everyMinute();
 *
 * وإعادة الدفع آمنة: `claimOne` ذرّي، فمهمّتان لنفس المعرّف تعني أن
 * الثانية تجد الصفّ مسحوباً وتنصرف.
 *
 * ═══ ولا يمسّ قنوات السحب ═══
 *
 * SMS ينتظر هاتفاً ينادي — لا شيء هنا يسرّع ذلك، ودفعُ مهمّةٍ له يعني
 * عاملين يتنازعان نفس الصفّ.
 */
class DispatchQueuedCommand extends Command
{
    protected $signature = 'sms:dispatch {--limit=50 : سقف ما يُدفَع في التشغيلة}';

    protected $description = 'إعادة دفع رسائل القنوات الخادمية العالقة في الطابور';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $pending = SmsMessage::query()
            ->where('channel', DeliveryChannel::WhatsApp)
            ->where('status', SmsStatus::Queued)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($pending as $id) {
            DeliverWhatsApp::dispatch((int) $id);
        }

        $this->info("دُفعت {$pending->count()} رسالة.");

        return self::SUCCESS;
    }
}
