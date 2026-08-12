<?php

namespace App\Modules\Sms\Services;

use App\Models\User;
use App\Modules\Identity\Support\YemeniPhone;
use App\Modules\Sms\Enums\ChallengeResult;
use App\Modules\Sms\Models\PhoneChallenge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * التوثيق العكسي: العميل يرسل إلينا بدل أن نرسل إليه.
 *
 * ═══ لماذا هذه القناة أقوى إثباتاً لا مجرد بديل ═══
 *
 * في التوثيق العادي نرسل رمزاً ونثق أن من كتبه يملك الهاتف — والإثبات
 * سرّية الرمز. وهنا يصلنا الرمز **من الرقم نفسه**، ورقمُ المرسِل تكتبه
 * الشبكة لا المستخدم. فالملكية تُثبَت بالمسار لا بالنقل.
 *
 * وهي القناة الوحيدة التي لا تفشل بحظرنا: المشغّل يوصّل من العميل إلينا،
 * لا العكس. ولا تكلّف الخادم شيئاً.
 *
 * ═══ والرمز معروضٌ على الشاشة بقصد ═══
 *
 * وهذا ما يجعل خلطه بالتوثيق العادي كارثة: رمزٌ معروض يُكتب في نموذج
 * `/verify-phone` فيُقبل، فيوثّق أيُّ أحد أيَّ رقم. ولذلك مخزنٌ مستقل —
 * انظر تعليق الهجرة.
 *
 * ═══ الرمز حروفٌ لا أرقام ═══
 *
 * ستّ خانات رقمية تُشبه كل رمز مصرفي في صندوق الوارد، فيصعب على مرشّح
 * الهاتف تمييزها. والحروف مع بادئة `SQ` تجعل المطابقة على الهاتف دقيقة —
 * وهذا شرط الخصوصية: لا يُرسَل إلى الخادم إلا ما يطابق النمط.
 *
 * والأبجدية بلا `O` و`0` و`I` و`1` و`L`: الرمز يُقرأ من شاشة ويُكتب
 * بالإبهام، والخلط بينها يُنتج فشلاً يظنّه صاحبه عطلاً.
 */
final class ChallengeVerifier
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 6;

    private const MAX_ATTEMPTS = 5;

    /** تحدّيات في النافذة لكل رقم — يمنع توليداً بلا حدّ */
    private const MAX_ISSUES = 5;

    private const ISSUE_WINDOW_MINUTES = 10;

    /**
     * إصدار تحدٍّ وعرضه لصاحبه.
     *
     * @return array{token: string, expires_at: Carbon}
     *
     * @throws RuntimeException عند تجاوز حدّ الإصدار
     */
    public function issue(User $user): array
    {
        $phone = $user->phone;

        if ($phone === null) {
            throw new RuntimeException('لا يوجد رقم جوال على هذا الحساب.');
        }

        $this->guardIssueRate($phone);

        // تحدٍّ واحد صالح لكل رقم — السابق يُستهلك
        PhoneChallenge::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $token = $this->generateToken();
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        PhoneChallenge::create([
            'user_id' => $user->id,
            'phone' => $phone,
            'token_hash' => Hash::make($token),
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * استهلاك رسالة واردة.
     *
     * ═══ المرسِل هو الإثبات ═══
     *
     * البحث بـ`$from` لا بالرمز: نجد تحدّيات هذا الرقم ثم نفحص التجزئة.
     * فرمزٌ صحيح وصل من رقم آخر لا يطابق شيئاً — وهو بالضبط ما يجب أن
     * يحدث: من نسخ الرمز عن شاشة غيره لا يملك رقمه.
     *
     * ولهذا لا حاجة إلى تجزئة قابلة للبحث: bcrypt، كما في
     * `PhoneVerificationService`.
     */
    public function consume(string $from, string $token): ChallengeResult
    {
        $phone = YemeniPhone::normalize($from);

        if ($phone === null) {
            return ChallengeResult::UnknownSender;
        }

        $challenge = PhoneChallenge::query()->usableFor($phone)->first();

        if ($challenge === null) {
            return ChallengeResult::NoChallenge;
        }

        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            return ChallengeResult::Locked;
        }

        // العدّاد قبل الفحص — بلا ذلك يُتخطّى السقف بإنهاء الطلب مبكراً
        $challenge->increment('attempts');

        if (! Hash::check($this->normalizeToken($token), $challenge->token_hash)) {
            return ChallengeResult::Invalid;
        }

        $challenge->update(['consumed_at' => now()]);

        $user = $challenge->user;

        if ($user === null) {
            return ChallengeResult::NoChallenge;
        }

        /**
         * الرقم يُوثَّق، ولا يُغيَّر.
         *
         * `markPhoneAsVerified` لا `forceFill(['phone' => $from])`: التحدّي
         * صدر لرقمٍ بعينه وطابقه المرسِل، فلا شيء يُحدَّث. ولو كتبنا الرقم
         * من الرسالة الواردة لصار مسارُ توثيقٍ يغيّر هوية الحساب — وهو
         * ما لا يجوز أن يقع من رسالة نصية.
         */
        $user->markPhoneAsVerified();

        return ChallengeResult::Verified;
    }

    /** الرمز كما يُعرَض: «SQ 4F7K2M» */
    public function display(string $token): string
    {
        return trim($this->prefix().' '.$token);
    }

    public function prefix(): string
    {
        return mb_strtoupper((string) config('sms.inbound.prefix', 'SQ'));
    }

    /** رقمنا الذي يُرسل إليه العميل — بصيغة العرض المحلية */
    public function inboundNumber(): ?string
    {
        $number = config('sms.inbound.number');

        return is_string($number) && $number !== ''
            ? (YemeniPhone::forDisplay(YemeniPhone::normalize($number)) ?? $number)
            : null;
    }

    /**
     * انتزاع الرمز من نصّ رسالة واردة.
     *
     * البادئة مطلوبة: بلاها يلتقط النمطُ أيَّ ستّ خانات في صندوق الوارد.
     * والفراغات والشرطات تُهمَل لأن من يكتب بالإبهام يضعها.
     *
     * والمطابقة بلا حسّاسية للحالة (`i`): الرمز يُعرَض بحروف كبيرة، ولوحة
     * مفاتيح الجوال تبدأ صغيرةً ما لم يرفعها صاحبها. ورفضُ `sq 4f7k2m`
     * يُقرأ عطلاً في المنصة لا خطأً في الكتابة — وهو نفس ما يفعله مرشّح
     * الهاتف بـ`ascii_upcase`.
     */
    public function extractToken(string $body): ?string
    {
        $pattern = '/'.preg_quote($this->prefix(), '/').'[\s\-_:]*([A-Za-z0-9]{'.self::LENGTH.'})/iu';

        return preg_match($pattern, $body, $matches) === 1
            ? $this->normalizeToken($matches[1])
            : null;
    }

    public function ttlMinutes(): int
    {
        return (int) config('sms.inbound.ttl_minutes', 15);
    }

    // ─── الداخل ───

    private function generateToken(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $token = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            // random_int لا rand: رمزٌ يمكن تخمينه من التوقيت لا يوثّق شيئاً
            $token .= $alphabet[random_int(0, $max)];
        }

        return $token;
    }

    /**
     * الرفع والقصّ فقط — ولا تصحيح للحروف الملتبسة.
     *
     * الالتباس مُنِع في الأبجدية نفسها: لا `O` ولا `0` ولا `I` ولا `1`
     * ولا `L`. وتصحيحٌ هنا فوق ذلك يعني قبول رمزٍ لم يُولَّد قط — أي
     * توسيعَ مساحة ما يُقبل بلا أن يُصلح شيئاً.
     */
    private function normalizeToken(string $token): string
    {
        return mb_strtoupper(trim($token));
    }

    private function guardIssueRate(string $phone): void
    {
        $issued = PhoneChallenge::query()
            ->where('phone', $phone)
            ->where('created_at', '>=', now()->subMinutes(self::ISSUE_WINDOW_MINUTES))
            ->count();

        if ($issued >= self::MAX_ISSUES) {
            throw new RuntimeException('طلبات كثيرة. انتظر قليلاً ثم أعد المحاولة.');
        }
    }
}
