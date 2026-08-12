<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Identity\Models\UserDevice;
use Illuminate\Support\Facades\DB;

/**
 * دفتر أجهزة المستخدم ورموز الدفع.
 *
 * ═══ خدمة بلا HTTP بقصد ═══
 *
 * لا متحكّم ولا مسار هنا. التطبيق يسجّل جهازه عبر وحدة API حين تُبنى،
 * وهذه الخدمة هي ما ستستدعيه — وبقاؤها خالصةً من الطلب والاستجابة يعني
 * أن بناء الطبقة فوقها لن يحتاج تعديلها.
 *
 * ═══ الرمز الواحد لا يخدم حسابين ═══
 *
 * أخطر حالة في هذا الملف: جهاز يخرج منه مستخدم ويدخل آخر. المزوّد يعطي
 * التطبيق **نفس رمز الدفع** — فلو بقي الرمز مربوطاً بالحساب الأول لوصلت
 * رسائله الخاصة إلى جهاز يحمله شخص آخر. وهو تسريبٌ لا يظهر في أي اختبار
 * لا يفكّر فيه أحد.
 *
 * ولذلك register يمسح الرمز من كل صفٍّ آخر قبل أن يكتبه. المسح لا يحذف
 * الصفوف — الجهاز الآخر يبقى مسجَّلاً بلا رمز، فيستعيده صاحبه بأول تسجيل.
 *
 * ═══ الخروج يمسح الرمز ولا يحذف الجهاز ═══
 *
 * نفس السبب معكوساً: الصفّ سجلّ جهاز، وحذفه يفقد last_seen_at وتاريخ
 * الربط. المطلوب قطع التسليم لا محو الأثر.
 */
final class DeviceRegistrar
{
    private const PLATFORMS = ['web', 'android', 'ios'];

    public function register(
        User $user,
        string $deviceUuid,
        string $platform,
        ?string $pushToken = null,
    ): UserDevice {
        $platform = in_array($platform, self::PLATFORMS, true) ? $platform : 'web';

        return DB::transaction(function () use ($user, $deviceUuid, $platform, $pushToken): UserDevice {
            if ($pushToken !== null) {
                $this->detachTokenFromOtherDevices($pushToken, $user, $deviceUuid);
            }

            $device = UserDevice::query()->firstOrNew([
                'user_id' => $user->id,
                'device_uuid' => $deviceUuid,
            ]);

            $device->forceFill([
                'platform' => $platform,
                'last_seen_at' => now(),
                'created_at' => $device->created_at ?? now(),
            ]);

            // null لا يمسح رمزاً قائماً: تسجيل جهاز بلا رمز (نبضة حياة،
            // أو تطبيق لم يُمنح إذن الإشعار بعد) لا يعني أن الرمز السابق
            // بطل. المسح فعلٌ صريح عبر forget.
            if ($pushToken !== null) {
                $device->forceFill(['push_token' => $pushToken]);
            }

            $device->save();

            return $device;
        });
    }

    /** خروج من الجهاز — يقطع التسليم ويُبقي السجلّ */
    public function forget(User $user, string $deviceUuid): void
    {
        UserDevice::query()
            ->where('user_id', $user->id)
            ->where('device_uuid', $deviceUuid)
            ->update(['push_token' => null]);
    }

    /** @return list<string> */
    public function tokensFor(User $user): array
    {
        return UserDevice::query()
            ->where('user_id', $user->id)
            ->pushable()
            ->pluck('push_token')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * حذف ما رفضه المزوّد — الطرف الثاني من حلقة PushSender::send.
     *
     * @param  list<string>  $tokens
     * @return int عدد الصفوف التي فقدت رمزها
     */
    public function pruneTokens(array $tokens): int
    {
        if ($tokens === []) {
            return 0;
        }

        return UserDevice::query()
            ->whereIn('push_token', $tokens)
            ->update(['push_token' => null]);
    }

    private function detachTokenFromOtherDevices(string $token, User $user, string $deviceUuid): void
    {
        UserDevice::query()
            ->where('push_token', $token)
            ->where(function ($query) use ($user, $deviceUuid): void {
                $query->where('user_id', '!=', $user->id)
                    ->orWhere('device_uuid', '!=', $deviceUuid);
            })
            ->update(['push_token' => null]);
    }
}
