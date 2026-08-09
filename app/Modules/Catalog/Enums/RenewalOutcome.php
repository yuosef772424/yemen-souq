<?php

namespace App\Modules\Catalog\Enums;

/**
 * نتيجة محاولة التجديد.
 *
 * لماذا enum لا bool: «فشل التجديد» ليست حالة واحدة — الإعلان مباع،
 * أو ما زال بعيداً عن الانتهاء، أو مرفوض من الإدارة. وكل حالة تحتاج
 * رسالة مختلفة للمعلن. bool يجبر المتحكّم على إعادة استنتاج السبب،
 * فيتكرّر الشرط في موضعين ويتباعدان مع الوقت.
 */
enum RenewalOutcome: string
{
    case Renewed = 'renewed';
    case TooEarly = 'too_early';
    case NotRenewable = 'not_renewable';

    public function message(): string
    {
        return match ($this) {
            self::Renewed => 'تم تجديد الإعلان.',
            self::TooEarly => 'الإعلان ما زال سارياً — التجديد متاح في آخر سبعة أيام قبل الانتهاء.',
            self::NotRenewable => 'لا يمكن تجديد هذا الإعلان بحالته الحالية.',
        };
    }

    public function succeeded(): bool
    {
        return $this === self::Renewed;
    }
}