<?php

namespace App\Modules\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

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
            'media' => $this->media(),
        ]);
    }

    /**
     * حالة قرص الوسائط — بلا كشف مسار.
     *
     * ═══ لماذا يستحق الظهور هنا ═══
     *
     * عطل التخزين لا يُعلن عن نفسه: الصفحة تُقلع، والصحة «ok»، والصور
     * وحدها تعود 404 — فيُقرأ العطل خطأً في الرفع أو في الشبكة. وهذه
     * الحقول تفصل الاحتمالات الثلاثة من الخارج بلا دخول إلى الحاوية:
     *
     *   persistent=false → القرص داخل الصورة، والصور تُمحى مع كل نشر
     *   writable=false   → القرص مركَّب لكن مملوك لـroot، فالرفع يفشل
     *   linked=false     → الرابط الرمزي غائب، فالكتابة تنجح والقراءة لا
     *
     * ولا مسارات مطلقة في الرد: النقطة عامة بلا مصادقة، وبنية ملفات
     * الخادم ليست مما يُذاع. بولياناتٌ تكفي للتشخيص ولا تُفيد مهاجماً.
     *
     * @return array<string, mixed>
     */
    private function media(): array
    {
        $disk = (string) config('filesystems.media', 'public');
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');

        // التخزين الكائني: لا قرص محلياً فلا معنى للديمومة ولا للرابط
        if ($driver !== 'local') {
            return [
                'driver' => $driver,
                'persistent' => true,
                'multi_replica_safe' => true,
            ];
        }

        $root = (string) config("filesystems.disks.{$disk}.root", '');
        $link = public_path('storage');

        return [
            'driver' => 'local',
            // الجذر خارج مجلد التطبيق يعني قرصاً مركَّباً لا مجلداً في الصورة
            'persistent' => $root !== '' && ! str_starts_with($root, base_path()),
            'writable' => $root !== '' && is_dir($root) && is_writable($root),
            // file_exists لا is_link: الأخير يصدُق على رابطٍ مكسور هدفُه
            // غير موجود — وهو بالضبط العطل المقصود كشفه. والأول يتبع
            // الرابط، فيصدُق حين يصل إلى شيء قائم فعلاً
            'linked' => file_exists($link),
            // القرص الدائم يُركَّب على نسخة واحدة لا غير
            'multi_replica_safe' => false,
        ];
    }
}
