<?php

namespace App\Modules\Api\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * إصدار رموز الوصول وإبطالها — المدخل الوحيد.
 *
 * ═══ لماذا خدمة لا سطر ‎createToken‎ في المتحكّم ═══
 *
 * نفس حجّة `Notifier` و`AuditLogger`: ثلاثة مسارات تُصدر رمزاً (التسجيل،
 * الدخول، تغيير كلمة المرور)، وكلٌّ منها يجب أن يطبّق العمر والسقف.
 * ونسيان أحدهما لا يُنتج خطأً بل رمزاً لا ينتهي — وهو بالضبط ما وُضعت
 * السياسة لمنعه.
 *
 * ═══ السقف يحذف الأقدم لا يرفض الجديد ═══
 *
 * البديل المرفوض: «تجاوزت عدد الأجهزة، احذف واحداً». من يعيد تنصيب
 * التطبيق للمرة الحادية عشرة يُمنع من الدخول إلى حسابه — والحلّ في
 * شاشة لا يصل إليها إلا بالدخول. الحذف الصامت للأقدم يُخرج جهازاً لم
 * يُستعمل منذ شهور، وهو ما يريده صاحبه أصلاً.
 *
 * ═══ الرمز يُعرض مرة واحدة ═══
 *
 * `plainTextToken` هو النص الوحيد؛ وما يُكتب في `personal_access_tokens`
 * تجزئته. ولذلك تُرجع هذه الخدمة النص في الاستجابة ولا سبيل إلى
 * استعادته بعدها — نفس مبدأ `PhoneVerificationService`: من يقرأ الجدول
 * لا يحصل على مفاتيح.
 */
final class TokenIssuer
{
    /**
     * @param  list<string>  $abilities
     * @return array{token: string, expires_at: ?string}
     */
    public function issue(User $user, ?string $name = null, array $abilities = ['*']): array
    {
        $this->pruneExpired($user);
        $this->enforceCeiling($user);

        $expiresAt = $this->expiry();

        $token = $user->createToken(
            $name ?: (string) config('api.tokens.name', 'mobile'),
            $abilities,
            $expiresAt,
        );

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }

    /** خروج من هذا الجهاز وحده */
    public function revokeCurrent(User $user): void
    {
        $token = $user->currentAccessToken();

        // قد يكون null حين يُستدعى المسار بحارس الجلسة لا برمز —
        // حالة واقعية في الاختبارات وفي استعمال المتصفح للـAPI
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }
    }

    /**
     * خروج من كل الأجهزة.
     *
     * يُستدعى بعد تغيير كلمة المرور: من غيّرها الغالب أنه فقد جهازاً أو
     * خشي اختراقاً، وكلمةٌ جديدة مع رمزٍ قديم يعمل ليست استعادة. نفس
     * منطق تدوير `remember_token` في `PasswordResetController`.
     */
    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }

    private function expiry(): ?Carbon
    {
        $days = (int) config('api.tokens.ttl_days', 30);

        return $days > 0 ? now()->addDays($days) : null;
    }

    /**
     * المنتهية تُحذف قبل العدّ.
     *
     * بلا هذا يمتلئ السقف برموز ميتة، فيُطرد جهازٌ حيّ ليُفسح لواحد
     * انتهى منذ شهر. وsanctum:prune-expired أمرٌ مجدول قد لا يُشغَّل —
     * والتنظيف عند الإصدار لا يعتمد على مجدول.
     */
    private function pruneExpired(User $user): void
    {
        $user->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    private function enforceCeiling(User $user): void
    {
        $max = (int) config('api.tokens.max_per_user', 10);

        if ($max <= 0) {
            return;
        }

        // ‎- 1‎ لأن العدّ يجري قبل إصدار الرمز الجديد
        $excess = $user->tokens()->count() - ($max - 1);

        if ($excess <= 0) {
            return;
        }

        $oldest = $user->tokens()
            ->orderBy('last_used_at')
            ->orderBy('created_at')
            ->limit($excess)
            ->pluck('id');

        $user->tokens()->whereIn('id', $oldest)->delete();
    }
}
