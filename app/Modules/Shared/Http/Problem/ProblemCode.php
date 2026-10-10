<?php

namespace App\Modules\Shared\Http\Problem;

/**
 * رموز الأخطاء الثابتة في واجهة ‎/api‎ — العقد بين الخادم والتطبيق.
 *
 * ═══ لماذا enum لا نصوص متفرقة ═══
 *
 * التطبيق يفرّق حالتين بـ`code` لا بمطابقة نصّ عربي (انظر
 * docs/architecture/api.md). ورمزٌ يُكتب نصّاً في عشرة متحكّمات يُكتب
 * بعشر صيغ: `phone_taken` هنا و`phoneTaken` هناك، ولا يكشف الفرقَ أيُّ
 * اختبار لأنه في سلسلة لا في نوع.
 *
 * والقيمة snake_case لأنها تظهر في `type` كذلك:
 * `urn:yemen-souq:problem:<code>` — المرجع RFC 9457 §3.1.1 (حقل `type`
 * مُعرِّف للمشكلة لا رابط مقصود الفتح، وURN مقبول).
 *
 * ═══ `invalid_code` و`invalid_verification_code` ═══
 *
 * رمزان لمعنى واحد (رمز تحقق خاطئ) وُرِدا من مصدرين: `invalid_code` هو
 * ما تعتمده `ApiException::invalidCode()` ويقع في `errors.code`، و
 * `invalid_verification_code` من قائمة الرموز الأولية. يُبقى كلاهما حتى
 * يقرّر مالك التدفّق أيّهما يستعمل — وحذف أحدهما لاحقاً أسهل من استعادته.
 *
 * ═══ مجموعتان ═══
 *
 * الأولى: الرموز الستة العامة التي يولّدها معالج الاستثناءات وحده.
 * الثانية: رموز تدفّقات الدخول والتسجيل — تُرمى من الخدمات عبر
 * `ApiProblemException`.
 * والثالثة رموز نقل (`bad_request`…) يحتاجها المعالج لتسمية بقية أخطاء HTTP
 * بدل حشرها كلها تحت رمز واحد؛ لا تُرمى من الخدمات.
 */
enum ProblemCode: string
{
    // ─── عامّة ───
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';

    // ─── الدخول والتسجيل والتوثيق ───
    case InvalidCredentials = 'invalid_credentials';
    case PhoneTaken = 'phone_taken';
    case InvalidCode = 'invalid_code';
    case InvalidVerificationCode = 'invalid_verification_code';
    case VerificationCodeExpired = 'verification_code_expired';
    case TooManyAttempts = 'too_many_attempts';
    case ResendTooSoon = 'resend_too_soon';

    // ─── نقل: بقية حالات HTTP ───
    case BadRequest = 'bad_request';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case PayloadTooLarge = 'payload_too_large';
    case UnsupportedMediaType = 'unsupported_media_type';
    case PageExpired = 'page_expired';
    case ServiceUnavailable = 'service_unavailable';
    case RequestFailed = 'request_failed';

    /** عنوان قصير ثابت للنوع — لا يتغيّر بين حدثين من النوع نفسه (RFC 9457 §3.1.3) */
    public function title(): string
    {
        return match ($this) {
            self::ValidationFailed => 'البيانات غير صالحة',
            self::Unauthenticated => 'تسجيل الدخول مطلوب',
            self::Forbidden => 'غير مسموح',
            self::NotFound => 'غير موجود',
            self::RateLimited => 'طلبات كثيرة',
            self::ServerError => 'خطأ في الخادم',
            self::InvalidCredentials => 'بيانات الدخول غير صحيحة',
            self::PhoneTaken => 'رقم الجوال مستخدم',
            self::InvalidCode => 'رمز التحقق غير صحيح',
            self::InvalidVerificationCode => 'رمز التحقق غير صحيح',
            self::VerificationCodeExpired => 'انتهت صلاحية رمز التحقق',
            self::TooManyAttempts => 'محاولات كثيرة',
            self::ResendTooSoon => 'إعادة الإرسال مبكرة',
            self::BadRequest => 'طلب غير صالح',
            self::MethodNotAllowed => 'الطريقة غير مدعومة',
            self::Conflict => 'تعارض مع الحالة الحالية',
            self::PayloadTooLarge => 'الطلب كبير جداً',
            self::UnsupportedMediaType => 'نوع المحتوى غير مدعوم',
            self::PageExpired => 'انتهت الجلسة',
            self::ServiceUnavailable => 'الخدمة غير متاحة',
            self::RequestFailed => 'تعذّر تنفيذ الطلب',
        };
    }

    /** الرسالة الآمنة حين لا يحمل الاستثناء رسالة أوضح — بلا أي تفصيل داخلي */
    public function defaultDetail(): string
    {
        return match ($this) {
            self::ValidationFailed => 'بعض الحقول غير صالحة. راجعها ثم أعد المحاولة.',
            self::Unauthenticated => 'سجّل الدخول للمتابعة.',
            self::Forbidden => 'لا تملك صلاحية تنفيذ هذا الإجراء.',
            self::NotFound => 'المورد المطلوب غير موجود.',
            self::RateLimited => 'طلبات كثيرة في وقت قصير. انتظر قليلاً ثم أعد المحاولة.',
            self::ServerError => 'حدث خطأ غير متوقع. حاول مرة أخرى بعد قليل.',
            self::InvalidCredentials => 'البيانات غير صحيحة.',
            self::PhoneTaken => 'رقم الجوال هذا مستخدم في حساب آخر.',
            self::InvalidCode => 'رمز التحقق غير صحيح.',
            self::InvalidVerificationCode => 'رمز التحقق غير صحيح.',
            self::VerificationCodeExpired => 'انتهت صلاحية رمز التحقق. اطلب رمزاً جديداً.',
            self::TooManyAttempts => 'محاولات كثيرة. انتظر قليلاً ثم أعد المحاولة.',
            self::ResendTooSoon => 'لا يمكن إعادة الإرسال الآن. انتظر قليلاً ثم أعد المحاولة.',
            self::BadRequest => 'الطلب غير صالح.',
            self::MethodNotAllowed => 'طريقة الطلب غير مدعومة لهذا المسار.',
            self::Conflict => 'يتعارض الطلب مع الحالة الحالية للمورد.',
            self::PayloadTooLarge => 'حجم الطلب أكبر من المسموح.',
            self::UnsupportedMediaType => 'نوع المحتوى المرسَل غير مدعوم.',
            self::PageExpired => 'انتهت صلاحية الجلسة. أعد تحميل الصفحة ثم حاول مرة أخرى.',
            self::ServiceUnavailable => 'الخدمة غير متاحة مؤقتاً. حاول بعد قليل.',
            self::RequestFailed => 'تعذّر تنفيذ الطلب.',
        };
    }

    /**
     * الحالة الافتراضية للرمز.
     *
     * `invalid_credentials` بـ422 لا 401 عن قصد: الدخول الخاطئ يعود اليوم
     * ValidationException تحت `errors` (وتختبره ApiAuthTest)، والتطبيقات
     * المنشورة تعالج 422 كخطأ حقل — وتغيير الحالة يكسرها قبل أن تُحدَّث.
     */
    public function defaultStatus(): int
    {
        return match ($this) {
            self::ValidationFailed,
            self::InvalidCredentials,
            self::PhoneTaken,
            self::InvalidCode,
            self::InvalidVerificationCode,
            self::VerificationCodeExpired => 422,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::RateLimited,
            self::TooManyAttempts,
            self::ResendTooSoon => 429,
            self::ServerError => 500,
            self::BadRequest => 400,
            self::MethodNotAllowed => 405,
            self::Conflict => 409,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::PageExpired => 419,
            self::ServiceUnavailable => 503,
            self::RequestFailed => 400,
        };
    }

    /** الرمز المناسب لحالة HTTP حين لا يحمل الاستثناء رمزاً أدقّ */
    public static function forStatus(int $status): self
    {
        return match (true) {
            $status === 400 => self::BadRequest,
            $status === 401 => self::Unauthenticated,
            $status === 403 => self::Forbidden,
            $status === 404 => self::NotFound,
            $status === 405 => self::MethodNotAllowed,
            $status === 409 => self::Conflict,
            $status === 413 => self::PayloadTooLarge,
            $status === 415 => self::UnsupportedMediaType,
            $status === 419 => self::PageExpired,
            $status === 422 => self::ValidationFailed,
            $status === 429 => self::RateLimited,
            $status === 503 => self::ServiceUnavailable,
            $status >= 500 => self::ServerError,
            default => self::RequestFailed,
        };
    }

    /** مُعرِّف النوع في الجسم: urn:yemen-souq:problem:<code> */
    public static function typeFor(string $code): string
    {
        return 'urn:yemen-souq:problem:'.$code;
    }
}
