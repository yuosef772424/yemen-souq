<?php

namespace App\Modules\Sms\Enums;

/**
 * حالة الرسالة في الطابور.
 *
 * ═══ خمس حالات لا عمود «تم التسليم» منطقي ═══
 *
 * `delivered: bool` كان كافياً لو كان الطريق خطوة واحدة. وهو ثلاث:
 * تُكتب، يسحبها الهاتف، يُرسلها. وكل فاصلٍ بينها حالةٌ يقع فيها عطل
 * مختلف — والعمود المنطقي يجعل الثلاثة «لم يُسلَّم بعد»، فلا يُعرف
 * أبداً أهي تنتظر هاتفاً نائماً أم عالقة في سحبةٍ ماتت.
 *
 *   Queued   في الطابور، لم يرها أحد
 *   Claimed  سحبها هاتفٌ ولم يؤكّد — قد يكون أرسل ومات قبل التأكيد
 *   Sent     أكّد الهاتف الإرسال
 *   Failed   أكّد الفشل، أو استُنفدت المحاولات
 *   Expired  فات أجلها قبل أن تُرسَل — والرمز الذي تحمله ميت أصلاً
 *
 * والتفريق بين `Failed` و`Expired` ليس تجميلاً: الأولى تقول «الهاتف
 * لا يرسل» والثانية تقول «الهاتف بطيء أو نائم». الأولى تحتاج بطاقة
 * SIM، والثانية تحتاج `termux-wake-lock`.
 */
enum SmsStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Sent = 'sent';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'في الطابور',
            self::Claimed => 'مسحوبة',
            self::Sent => 'أُرسلت',
            self::Failed => 'فشلت',
            self::Expired => 'انتهى أجلها',
        };
    }

    /** الحالات التي لا يتغيّر بعدها شيء — لا إعادة سحب ولا تأكيد */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Sent, self::Failed, self::Expired => true,
            self::Queued, self::Claimed => false,
        };
    }
}
