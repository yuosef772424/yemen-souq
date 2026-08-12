<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Requests\RegisterDeviceRequest;
use App\Modules\Notifications\Services\DeviceRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الطبقة التي كان `DeviceRegistrar` ينتظرها.
 *
 * تعليق تلك الخدمة يقول: «لا متحكّم ولا مسار هنا. التطبيق يسجّل جهازه
 * عبر وحدة API حين تُبنى، وهذه الخدمة هي ما ستستدعيه». هذا هو الملف —
 * وهو رقيق كما وُعد: كل قرار خطير (فصل الرمز عن الأجهزة الأخرى، إبقاء
 * الصفّ عند الخروج، عدم مسح رمز قائم بـnull) يعيش في الخدمة.
 *
 * ═══ التسجيل بـPUT لا POST ═══
 *
 * الفعل مُتماثل: التطبيق يسجّل جهازه عند كل إقلاع وعند كل تدوير للرمز،
 * والنتيجة هي نفسها مهما تكرّر — `firstOrNew` على (مستخدم، معرّف جهاز).
 * وPOST يَعِد بإنشاء صفٍّ في كل نداء، وهو وعدٌ لا يفي به هذا المسار.
 */
class DeviceController
{
    public function __construct(
        private readonly DeviceRegistrar $devices,
    ) {}

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $device = $this->devices->register(
            $request->user(),
            (string) $request->validated('device_uuid'),
            (string) $request->validated('platform'),
            $request->validated('push_token'),
        );

        return response()->json([
            'message' => 'سُجّل الجهاز.',
            'data' => [
                'device_uuid' => $device->device_uuid,
                'platform' => $device->platform,
                // الرمز نفسه لا يُعاد — العميل يملكه أصلاً، وإعادته
                // تضعه في سجلّات وسيطة بلا فائدة
                'push_enabled' => $device->push_token !== null,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * خروج من الجهاز — يقطع التسليم ويُبقي السجلّ.
     *
     * لا 404 حين لا يوجد صفّ: الفعل «لا تُرسل إلى هذا الجهاز»، ونتيجته
     * محقَّقة سواء وُجد الصفّ أم لا. وخطأٌ هنا يجعل التطبيق يعيد
     * المحاولة على شيء تمّ أصلاً.
     */
    public function destroy(Request $request, string $deviceUuid): JsonResponse
    {
        $this->devices->forget($request->user(), $deviceUuid);

        return response()->json(['message' => 'أُوقف الدفع إلى هذا الجهاز.']);
    }
}
