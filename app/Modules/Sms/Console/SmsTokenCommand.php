<?php

namespace App\Modules\Sms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * توليد سرّ العامل.
 *
 * ═══ يُطبع ولا يُكتب ═══
 *
 * الأمر لا يلمس `.env`. سببان: الملف قد يكون مُدارَاً خارج المستودع
 * (متغيّرات بيئة في الاستضافة، أو خزنة أسرار)، وكتابةٌ آلية فيه تنشئ
 * سرّاً لا يعرف أحدٌ متى تغيّر. والطباعة تجعل الخطوة واعية: تنسخه إلى
 * الخادم وإلى الهاتف، وتعرف أنك فعلت.
 *
 * ═══ ولماذا 64 محرفاً ═══
 *
 * السرّ هو الشيء الوحيد بين المهاجم وبين طابورٍ فيه رموز توثيق — ومن
 * يسحب الرمز قبل الهاتف يوثّق أي حساب. والطول هنا أرخص حماية ممكنة:
 * لا يُحفظ ولا يُكتب يدوياً، فلا مقابل لقصره.
 */
class SmsTokenCommand extends Command
{
    protected $signature = 'sms:token';

    protected $description = 'توليد سرّ عامل الرسائل النصية';

    public function handle(): int
    {
        $token = Str::random(64);

        $this->newLine();
        $this->line('ضعه في .env على الخادم:');
        $this->newLine();
        $this->line("  SMS_WORKER_TOKEN={$token}");
        $this->newLine();
        $this->line('وفي إعداد العامل على الهاتف (~/.souq-sms.env):');
        $this->newLine();
        $this->line("  SOUQ_SMS_TOKEN={$token}");
        $this->newLine();
        $this->warn('لن يُعرَض ثانيةً — انسخه الآن. وتغييره يوقف العامل حتى يُحدَّث على الطرفين.');
        $this->newLine();

        return self::SUCCESS;
    }
}
