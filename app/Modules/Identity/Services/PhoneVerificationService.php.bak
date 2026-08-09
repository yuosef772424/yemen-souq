<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use App\Modules\Identity\Enums\VerificationResult;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Senders\DisplaySender;
use App\Modules\Identity\Senders\SmsGatewaySender;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * إصدار رموز التحقق والتحقق منها.
 *
 * كل السياسة هنا: طول الرمز، الصلاحية، سقف المحاولات، حدّ الإرسال.
 * المُرسل يوصّل فقط. هذا يعني أن تبديل المزوّد لا يغيّر سلوكاً أمنياً.
 *
 * الرمز لا يُخزَّن نصاً صريحاً أبداً — code_hash فقط. سبب مباشر: من
 * يقرأ الجدول (نسخة احتياطية مسروقة، منفذ SQL مكشوف) يستطيع توثيق
 * أي رقم. والتجزئة تجعل الجدول بلا قيمة للمهاجم.
 */
final class PhoneVerificationService
{
    public function __construct(
        private readonly ?PhoneVerificationSender $sender = null,
    ) {
    }

    /**
     * يُصدر رمزاً جديداً ويوصّله.
     *
     * @return string|null الرمز إن كانت القناة تعرضه، وإلا null
     * @throws RuntimeException عند تجاوز حدّ الإرسال
     */
    public function issue(string $phone, ?string $ipPrefix = null): ?string
    {
        $this->guardSendRate($phone);

        // رمز واحد صالح لكل رقم: الرموز السابقة تُستهلك.
        // بلا هذا يبقى رمز قديم صالحاً بعد طلب رمز جديد، فيتضاعف
        // سطح الهجوم بكل إعادة إرسال.
        PhoneVerification::where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        PhoneVerification::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($this->config('ttl_minutes')),
            'ip_prefix' => $ipPrefix,
            'created_at' => now(),
        ]);

        $sender = $this->sender();
        $sender->send($phone, $code);

        return $sender->displaysCode() ? $code : null;
    }

    public function verify(string $phone, string $code): VerificationResult
    {
        $record = PhoneVerification::query()->usableFor($phone)->first();

        if ($record === null) {
            return VerificationResult::Expired;
        }

        if ($record->attempts >= $this->config('max_attempts')) {
            return VerificationResult::Locked;
        }

        // العدّاد يزيد قبل الفحص: لو زاد بعده لكان بالإمكان تخطّيه
        // بإنهاء الطلب مبكراً، ولصار السقف بلا معنى.
        $record->increment('attempts');

        if (! Hash::check($code, $record->code_hash)) {
            return VerificationResult::Invalid;
        }

        $record->update(['consumed_at' => now()]);

        return VerificationResult::Verified;
    }

    /** كم ثانية حتى ينتهي الرمز الحالي — للعرض في الواجهة */
    public function secondsRemaining(string $phone): int
    {
        $record = PhoneVerification::query()->usableFor($phone)->first();

        return $record === null ? 0 : max(0, now()->diffInSeconds($record->expires_at, false));
    }

    // ---- الداخل ----

    private function guardSendRate(string $phone): void
    {
        $since = now()->subMinutes($this->config('send_window_minutes'));

        $sent = PhoneVerification::where('phone', $phone)
            ->where('created_at', '>=', $since)
            ->count();

        if ($sent >= $this->config('max_sends_per_window')) {
            throw new RuntimeException('طلبت رموزاً كثيرة. انتظر قليلاً ثم أعد المحاولة.');
        }
    }

    private function generateCode(): string
    {
        $length = (int) $this->config('code_length');
        $max = (10 ** $length) - 1;

        // random_int لا rand: مولّد آمن تشفيرياً. رمز يمكن تخمينه
        // من التوقيت لا يوثّق شيئاً.
        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function sender(): PhoneVerificationSender
    {
        if ($this->sender !== null) {
            return $this->sender;
        }

        return match (config('identity.phone_verification.driver')) {
            'sms' => app(SmsGatewaySender::class),
            default => app(DisplaySender::class),
        };
    }

    private function config(string $key): mixed
    {
        return config("identity.phone_verification.{$key}");
    }
}