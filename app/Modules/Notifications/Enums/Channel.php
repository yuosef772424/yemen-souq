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
 * والأربع كلها منفَّذة اليوم — انظر Notifier::sms وNotifier::email. وكلا
 * القناتين الجديدتين افتراضيها false في كل نوع رغم ذلك: انظر
 * NotificationType::defaultFor لسبب الإطفاء الذي لا علاقة له بالتنفيذ.
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

    /** القنوات التي لها تنفيذ فعلي اليوم */
    public function isImplemented(): bool
    {
        return true;
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
