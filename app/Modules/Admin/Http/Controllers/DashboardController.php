<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Models\AdminActionRecord;
use App\Modules\Admin\Services\AdminMetrics;
use App\Modules\Shared\Support\HealthCheck;
use Illuminate\View\View;

/**
 * لوحة القيادة.
 *
 * تعيد استخدام HealthCheck القائم بدل فحوص جديدة: الصنف موجود ويُشغَّل
 * على الخادم أصلاً، وكتابة فحوص ثانية للوحة تعني مصدرَي حقيقة عن صحة
 * نفس النظام — يختلفان بعد أول تعديل يُنسى في أحدهما.
 *
 * البديل المرفوض: عرض الفحوص في كل صفحة إدارية. الفحص يقرأ المخطط
 * والقرص ويعدّ صفوفاً — تكلفته مقبولة في صفحة تُفتح مرة، لا في كل
 * نقرة داخل اللوحة.
 */
class DashboardController
{
    public function __construct(
        private readonly AdminMetrics $metrics,
        private readonly HealthCheck $health,
    ) {}

    public function __invoke(): View
    {
        $checks = $this->health->run();

        return view('admin::dashboard', [
            'queue' => $this->metrics->queue(),
            'overview' => $this->metrics->overview(),
            'funnel' => $this->metrics->funnel(),
            'zeroQueries' => $this->metrics->zeroResultQueries(),
            'lastRolledUp' => $this->metrics->lastRolledUp(),

            // السليم لا يُعرض: عشرون سطراً أخضر تُخفي السطر الأحمر بينها
            'issues' => array_values(array_filter($checks, fn (array $c): bool => $c['status'] !== 'ok')),
            'checksTotal' => count($checks),
            'recent' => AdminActionRecord::query()->with('admin')->latestFirst()->limit(12)->get(),
        ]);
    }
}
