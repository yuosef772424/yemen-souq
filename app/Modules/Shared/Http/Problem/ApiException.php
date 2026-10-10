<?php

namespace App\Modules\Shared\Http\Problem;

/**
 * الاستثناء القابل للرمي من أي وحدة: `(code, status, field?, message)`.
 *
 * ```php
 * throw new ApiException(ProblemCode::PhoneTaken, 422, 'phone', 'الرقم مستخدم.');
 * throw new ApiException('phone_not_verified', 403, null, 'وثّق رقمك أولاً.');
 * throw ApiException::invalidCredentials();   // 422، errors.password
 * throw ApiException::tooManyAttempts(60);    // 429، Retry-After + retry_after
 * ```
 *
 * `field` غير null → يظهر `errors.<field>` بالرسالة نفسها. وnull → خطأ بلا
 * حقل (`errors` غائب). هو واجهة أضيق من `ApiProblemException` (الأم)
 * لمن لا يحتاج `errors` متعددة الحقول.
 *
 * مواضع الحقول في المصنِّعات ثابتة بقرار المنسّق، لأن التطبيق يعرض الرسالة
 * تحت الحقل المسمّى: الدخول الخاطئ تحت `password` لا `identifier`.
 */
class ApiException extends ApiProblemException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        ProblemCode|string $code,
        int $status,
        public readonly ?string $field,
        string $message,
        array $headers = [],
    ) {
        parent::__construct(
            $code,
            $status,
            $message,
            $field === null ? [] : [$field => [$message]],
            $headers,
        );
    }

    /** 422 — `errors.password` */
    public static function invalidCredentials(?string $message = null): self
    {
        return self::fromCode(ProblemCode::InvalidCredentials, 'password', $message);
    }

    /** 422 — `errors.phone` */
    public static function phoneTaken(?string $message = null): self
    {
        return self::fromCode(ProblemCode::PhoneTaken, 'phone', $message);
    }

    /** 422 — `errors.code` */
    public static function invalidCode(?string $message = null): self
    {
        return self::fromCode(ProblemCode::InvalidCode, 'code', $message);
    }

    /**
     * 429 مع `Retry-After` (ويصير `retry_after` في الجسد).
     *
     * @param  int  $retryAfter  ثوانٍ
     */
    public static function tooManyAttempts(int $retryAfter, ?string $field = null, ?string $message = null): self
    {
        return new self(
            ProblemCode::TooManyAttempts,
            429,
            $field,
            $message ?? ProblemCode::TooManyAttempts->defaultDetail(),
            ['Retry-After' => (string) max(1, $retryAfter)],
        );
    }

    private static function fromCode(ProblemCode $code, ?string $field, ?string $message): self
    {
        return new self($code, $code->defaultStatus(), $field, $message ?? $code->defaultDetail());
    }
}
