<?php

namespace App\Modules\Shared\Http\Problem;

use InvalidArgumentException;
use RuntimeException;

/**
 * الاستثناء النطاقي لأخطاء ‎/api‎ — ما تُلقيه الخدمات والمتحكّمات بدل
 * بناء JSON الخطأ يدوياً.
 *
 * ═══ لماذا استثناء لا `return response()->json(...)` ═══
 *
 * المتحكّم الذي يبني جسد الخطأ بيده يكتب `message` و`code` بترتيبٍ وأسماء
 * يخترعها في موضعه، فيتفرّع الشكل بعدد المتحكّمات. والاستثناء يُلقى من
 * أيّ طبقة (خدمة، سياسة) فيصل إلى معالج واحد يكتب الجسد الموحّد
 * (`ProblemDetails`) — فلا يحتاج مستدعي الخدمة إلى معرفة أنه يخدم HTTP.
 *
 * ═══ الحقول ═══
 *
 * - `code`: snake_case ثابت. يقبل `ProblemCode` أو نصّاً (رمز نطاقي خاص
 *   بميزة، كـ`phone_not_verified`) — بشرط الصيغة، وإلا فُشل البناء مبكراً
 *   لا عند أول عميل يحاول مطابقته.
 * - `detail`: رسالة عربية للمستخدم. **لا تضع فيها تفصيلاً داخلياً**:
 *   تصل كما هي إلى الشاشة.
 * - `errors`: `{field: [messages]}`.
 * - `headers`: مثل `Retry-After` — ويُنسخ تلقائياً إلى حقل `retry_after`.
 */
class ApiProblemException extends RuntimeException
{
    public readonly string $problemCode;

    /**
     * @param  array<string, string|list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        ProblemCode|string $code,
        public readonly int $status,
        string $detail,
        public readonly array $errors = [],
        public readonly array $headers = [],
        public readonly ?string $title = null,
    ) {
        $this->problemCode = $code instanceof ProblemCode ? $code->value : $code;

        if (preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/', $this->problemCode) !== 1) {
            throw new InvalidArgumentException("رمز المشكلة يجب أن يكون snake_case: [{$this->problemCode}].");
        }

        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException("حالة الخطأ يجب أن تكون بين 400 و599: [{$status}].");
        }

        parent::__construct($detail);
    }

    /**
     * رمز من الـenum بحالته ورسالته الافتراضيتين، وكلاهما قابل للتجاوز.
     *
     * @param  array<string, string|list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    public static function of(
        ProblemCode $code,
        ?string $detail = null,
        array $errors = [],
        array $headers = [],
        ?int $status = null,
    ): self {
        return new self(
            $code,
            $status ?? $code->defaultStatus(),
            $detail ?? $code->defaultDetail(),
            $errors,
            $headers,
        );
    }

    /**
     * خطأ حقلٍ واحد: 422 بالرمز المحدّد و`errors.<field>`.
     *
     * ```php
     * throw ApiProblemException::field('phone_taken', 'phone', 'الرقم مستخدم.');
     * ```
     *
     * `detail` هي رسالة الحقل نفسها: واجهة تعرض `message` وحده تعرض ما
     * يفيد، وواجهة تقرأ `errors.phone` تحصل على الرسالة ذاتها — وهذا ما
     * يفعله لارافل في ValidationException القياسية.
     */
    public static function field(
        ProblemCode|string $code,
        string $field,
        string $message,
        int $status = 422,
    ): self {
        return new self($code, $status, $message, [$field => [$message]]);
    }

    /**
     * `errors` بصيغة موحّدة: كل حقل قائمة رسائل.
     *
     * @return array<string, list<string>>
     */
    public function errorBag(): array
    {
        $bag = [];

        foreach ($this->errors as $field => $messages) {
            $bag[(string) $field] = array_values((array) $messages);
        }

        return $bag;
    }
}
