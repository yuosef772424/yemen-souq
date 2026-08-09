<?php

/**
 * php artisan tinker check.php
 *
 * نفس فحوص souq:check، عبر tinker — للاستخدام حين تريد إضافة فحص عابر
 * أسفل هذا الملف مؤقتاً. الفحوص الدائمة مكانها HealthCheck، لا هنا:
 * ما يُكتب في ملف مؤقت يُعاد اختراعه ناقصاً في المرة القادمة.
 *
 * ولا تكتب العربية في tinker التفاعلي على ويندوز — الملف هو الطريقة.
 */

use App\Modules\Shared\Support\HealthCheck;

foreach (app(HealthCheck::class)->run() as $result) {
    $mark = match ($result['status']) {
        'ok' => '[ ok ]',
        'warn' => '[warn]',
        default => '[FAIL]',
    };

    echo $mark . ' ' . $result['name'] . ': ' . $result['detail'] . PHP_EOL;
}

// ---- فحوص عابرة تحت هذا السطر ----