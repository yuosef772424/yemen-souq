<?php

namespace App\Console\Commands;

use App\Modules\Shared\Support\HealthCheck;
use Illuminate\Console\Command;

class CheckCommand extends Command
{
    protected $signature = 'souq:check {--fails-only : اعرض الأعطال فقط}';

    protected $description = 'فحص صحة المشروع — يكشف الأعطال الصامتة قبل أن تظهر للمستخدم';

    public function handle(HealthCheck $health): int
    {
        $results = $health->run();
        $failsOnly = (bool) $this->option('fails-only');

        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];

        $this->newLine();

        foreach ($results as $result) {
            $counts[$result['status']]++;

            if ($failsOnly && $result['status'] === 'ok') {
                continue;
            }

            $mark = match ($result['status']) {
                'ok' => '<fg=green>✓</>',
                'warn' => '<fg=yellow>!</>',
                default => '<fg=red>✗</>',
            };

            $this->line("  {$mark} {$result['name']}: {$result['detail']}");
        }

        $this->newLine();
        $this->line("  سليم {$counts['ok']} · تنبيه {$counts['warn']} · عطل {$counts['fail']}");
        $this->newLine();

        // رمز خروج غير صفري: يصلح لأن يكون بوابة في CI أو قبل النشر
        return $counts['fail'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}