<?php

namespace App\Modules\Admin\Enums;

/**
 * نتيجة إجراء على حساب.
 *
 * لماذا تعداد لا استثناء: الرفض هنا حالة متوقَّعة لا خلل. «لا تحظر
 * نفسك» ليس خطأ برمجياً يُرمى ويُسجَّل في اللوج، بل جواب طبيعي يُعرض
 * للمشرف. نفس نمط RenewalOutcome في وحدة الفهرس.
 */
enum UserActionOutcome
{
    case Done;
    case Self;
    case LastAdmin;
    case NoChange;

    public function succeeded(): bool
    {
        return $this === self::Done;
    }

    public function message(): string
    {
        return match ($this) {
            self::Done => 'نُفِّذ الإجراء.',
            self::Self => 'لا يمكنك تنفيذ هذا الإجراء على حسابك.',
            self::LastAdmin => 'هذا آخر مدير في النظام — لا يمكن تجريده أو إيقافه.',
            self::NoChange => 'الحساب على هذه الحالة أصلاً.',
        };
    }
}
