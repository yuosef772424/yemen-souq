<?php

namespace App\Modules\Identity\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * ميزانية محاولات الدخول لكل هوية — مشتركة بين الويب والـAPI.
 *
 * ═══ لماذا صنف لا سطران في كل متحكّم ═══
 *
 * الحدّ كان محلياً في `LoginController`: خمس محاولات لكل `identifier`.
 * ثم صارت هناك نقطة دخول ثانية في وحدة `Api` — ولو حملت مفتاحاً خاصاً
 * بها لصار الحدّان مستقلين، ولاستطاع من يخمّن كلمة مرور أن يضاعف
 * محاولاته بالتنقّل بين القناتين. أي أن إضافة الـAPI **تُضعف** حماية
 * الويب بلا أن يظهر ذلك في أي اختبار.
 *
 * المفتاح واحد لأن الحساب واحد. والقناة تفصيل نقل لا هوية.
 *
 * ═══ والهوية تُصغَّر وتُجزَّأ ═══
 *
 * `mb_strtolower` لأن البريد غير حسّاس للحالة، فـ`A@b.com` و`a@b.com`
 * حسابٌ واحد يجب أن يتقاسم الميزانية. والتجزئة لأن المفتاح يُكتب في
 * مخزن مؤقت قد يُفحص أو يُصدَّر — ولا داعي لأن يحمل بريد كل من حاول
 * الدخول.
 */
final class LoginThrottle
{
    /** محاولات فاشلة لكل هوية قبل القفل */
    public const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public static function tooManyAttempts(?string $identifier): bool
    {
        return RateLimiter::tooManyAttempts(self::key($identifier), self::MAX_ATTEMPTS);
    }

    public static function hit(?string $identifier): void
    {
        RateLimiter::hit(self::key($identifier), self::DECAY_SECONDS);
    }

    public static function clear(?string $identifier): void
    {
        RateLimiter::clear(self::key($identifier));
    }

    public static function availableIn(?string $identifier): int
    {
        return RateLimiter::availableIn(self::key($identifier));
    }

    private static function key(?string $identifier): string
    {
        return 'login:'.sha1(mb_strtolower(trim((string) $identifier)));
    }
}
