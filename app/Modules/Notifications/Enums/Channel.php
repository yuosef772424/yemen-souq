<?php

namespace App\Modules\Notifications\Enums;

/**
 * قنوات التسليم — مطابقة لقيم عمود channel في app_notifications
 * ولأسماء أعمدة notification_preferences.
 *
 * التطابق مقصود: PreferenceResolver يقرأ التفضيل بـ‎$row->{$channel->value}،
 * فاسم القناة هو اسم العمود. البديل — خريطة تحويل — يعني موضعاً ثانياً
 * يجب أن يُعدَّل عند إضافة قناة، ونسيانه يُسقط القناة الجديدة بصمت.
 *
 * الأربع منفَّذة في الشيفرة — انظر Notifier::sms وNotifier::email — لكن
 * الرسالة النصية لا تُعدّ مفعَّلة إلا حين تُشغَّل وحدة Sms (انظر
 * isImplemented). وكلا القناتين الجديدتين افتراضيها false في كل نوع
 * رغم ذلك: انظر NotificationType::defaultFor لسبب الإطفاء الذي لا علاقة
 * له بالتنفيذ.
 */
enum Channel: string
{
    case InApp = 'in_app';
    case Push = 'push';
    case Sms = 'sms';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'داخل التطبيق',
            self::Push => 'إشعار دافع',
            self::Sms => 'رسالة نصية',
            self::Email => 'بريد',
        };
    }

    /**
     * هل القناة مفعَّلة فعلاً الآن؟
     *
     * الرسالة النصية تتبع مفتاح وحدة Sms (`sms.enabled`، من SMS_ENABLED
     * ومطفأ افتراضياً): بلا تفعيلها لا رابط لـSmsSender في الحاوية، فلا
     * يصل أحداً شيء. ولذلك يُخفى عمودها من شاشات التفضيلات، ولا يسمح
     * PreferenceResolver::allows بها، فلا يُجدول إرسالها أصلاً. وبقية
     * القنوات كما كانت.
     *
     * الإخفاء عرضٌ لا حذف: القيمة المحفوظة في notification_preferences
     * تبقى كما هي (انظر PreferenceResolver::save)، فإذا فُعِّلت الوحدة
     * لاحقاً عاد اختيار المستخدم القديم.
     */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::Sms => (bool) config('sms.enabled', false),
            default => true,
        };
    }

    /**
     * ترتيب البلوغ — لعمود channel في app_notifications وحده.
     *
     * ذاك العمود ليس «أين يُعرض» بل «أبعد قناة بلغها الإشعار»، والرسالة
     * النصية والبريد يبلغان صاحبهما خارج التطبيق كلياً — أبعد من دفعٍ
     * يحتاج تطبيقاً مثبَّتاً وإذناً ممنوحاً. AppNotification::markDelivered
     * يستعمل هذا الترتيب ليمنع قناةً أقرب من إخفاء سجلّ قناة أبعد سبقتها.
     */
    public function reach(): int
    {
        return match ($this) {
            self::InApp => 0,
            self::Push => 1,
            self::Sms, self::Email => 2,
        };
    }
}
