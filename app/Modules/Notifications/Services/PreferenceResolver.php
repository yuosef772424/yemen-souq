<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

/**
 * هل يريد هذا المستخدم هذا النوع على هذه القناة؟
 *
 * ═══ ترتيب الأسئلة مقصود ═══
 *
 * 1. الإجبار قبل التفضيل — قرار الإشراف يصل داخل التطبيق مهما كانت
 *    الإعدادات. انظر NotificationType::isMandatory.
 * 2. التنفيذ قبل الرغبة — قناة بلا مزوّد لا تُرسِل ولو طلبها المستخدم.
 *    الفحص هنا لا عند الإرسال: بدونه يُكتب صفّ بقناة sms ثم لا يحدث
 *    شيء، فيظهر في الجدول كإشعار «أُرسل» ولم يصل أحداً.
 * 3. التفضيل المحفوظ، ثم الافتراض المعلَن عند غيابه.
 *
 * ═══ الكتابة بـupsert لا بـEloquent ═══
 *
 * المفتاح الأساسي مركّب (user_id, notification_type) بلا عمود id، وsave()
 * على نموذج قائم يبني ‎where id = ?‎ — عمودٌ غير موجود، فيحدّث صفراً من
 * الصفوف بلا خطأ. القراءة بـEloquent سليمة (لا تمسّ المفتاح)، والكتابة
 * تمرّ من هنا وحدها.
 */
final class PreferenceResolver
{
    /** ذاكرة الطلب: إشعار واحد قد يُفحص على قناتين، والصفّ واحد */
    private array $cache = [];

    public function allows(User $user, NotificationType $type, Channel $channel): bool
    {
        if ($type->isMandatory() && $channel === Channel::InApp) {
            return true;
        }

        if (! $channel->isImplemented()) {
            return false;
        }

        $preference = $this->preference($user, $type);

        return $preference === null
            ? $type->defaultFor($channel)
            : $preference->allows($channel);
    }

    /**
     * حفظ تفضيل — صفٌّ كامل دائماً.
     *
     * @param  array<string, bool>  $channels  اسم القناة => مسموح
     */
    public function save(User $user, NotificationType $type, array $channels): void
    {
        $row = $this->baseRow($user, $type);

        foreach ($channels as $name => $enabled) {
            if (array_key_exists($name, $row) && $name !== 'user_id' && $name !== 'notification_type') {
                $row[$name] = (bool) $enabled;
            }
        }

        DB::table('notification_preferences')->upsert(
            [$row],
            ['user_id', 'notification_type'],
            ['in_app', 'push', 'sms', 'email'],
        );

        unset($this->cache[$this->key($user, $type)]);
    }

    /**
     * نقطة بدء الحفظ: ما هو محفوظ فعلاً، وإلا الافتراضات.
     *
     * القنوات المخفية (الرسالة النصية حين تُطفأ وحدة Sms) لا تصل في
     * الطلب، فلو بدأ الحفظ من الافتراضات لمُسح اختيار المستخدم القديم
     * بصمت عند أول حفظ. البدء من الصفّ المحفوظ يُبقي القيمة كما هي.
     *
     * @return array<string, mixed>
     */
    private function baseRow(User $user, NotificationType $type): array
    {
        $row = NotificationPreference::defaultsFor($user, $type);
        $saved = $this->preference($user, $type);

        if ($saved === null) {
            return $row;
        }

        foreach (Channel::cases() as $channel) {
            $row[$channel->value] = $saved->allows($channel);
        }

        return $row;
    }

    /** كل التفضيلات الفعّالة لمستخدم — الافتراض حيث لا صفّ */
    public function allFor(User $user): array
    {
        $saved = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $row): string => $row->notification_type->value);

        $result = [];

        foreach (NotificationType::cases() as $type) {
            $row = $saved->get($type->value);

            foreach (Channel::cases() as $channel) {
                $result[$type->value][$channel->value] = $row === null
                    ? $type->defaultFor($channel)
                    : $row->allows($channel);
            }
        }

        return $result;
    }

    private function preference(User $user, NotificationType $type): ?NotificationPreference
    {
        $key = $this->key($user, $type);

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = NotificationPreference::query()
                ->where('user_id', $user->id)
                ->where('notification_type', $type->value)
                ->first();
        }

        return $this->cache[$key];
    }

    private function key(User $user, NotificationType $type): string
    {
        return $user->id.':'.$type->value;
    }
}
