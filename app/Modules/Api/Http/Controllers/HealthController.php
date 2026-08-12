<?php

namespace App\Modules\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * نبضة الحياة ونسخة العقد.
 *
 * ليست تكراراً لـ`/up`: ذاك يقول «العملية تعمل»، وهذا يقول «هذا الإصدار
 * من الـAPI قائم». والتطبيق المنشور على أجهزة الناس يحتاج الثاني —
 * إصدارٌ توقّف يجب أن يُقرأ كطلبِ تحديث لا كانقطاع شبكة.
 *
 * و`min_client` يُترك هنا لحين الحاجة: حين يصير في التطبيق تغييرٌ لا
 * يعمل بدونه، يُرفع الرقم فيعرف العميل أن عليه التحديث قبل أن يفشل.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => config('api.version'),
            'time' => now()->toIso8601String(),
        ]);
    }
}
