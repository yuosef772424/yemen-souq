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
 * Sms وEmail معلَنتان وغير موصولتين: العمودان موجودان في الجدول منذ
 * الهجرة الأولى، وحذفهما من هذا التعداد يجعل الجدول يَعِد بما لا يعرفه
 * الكود. الافتراضي لهما false في كل نوع — انظر NotificationType::defaultFor.
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
        return $this === self::InApp || $this === self::Push;
    }
}
