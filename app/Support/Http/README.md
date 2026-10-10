# عقد أخطاء `/api/*` (RFC 9457)

كل **استثناء** يُلقى من مسار يبدأ بـ`/api/` يخرج بـ`Content-Type: application/problem+json` وهذا الجسد:

> **تنبيه — حدود المعدّل المسمّاة لا تُرجع problem+json بعد.** `api-read` و`api-write` و`api-auth`
> و`sms-worker` تعرّف `->response(...)` مخصّصاً يبني JSON عادياً (`{"message": "…"}` بحالة 429)
> بدل استثناء، فلا يمرّ بهذا المعالج. أي `throttle` عارٍ بلا استجابة مخصّصة هو وحده الذي يتحوّل إلى
> `rate_limited`. تحويلها عمل لاحق، وتفصيله في «ما لا يغطّيه المعالج بعد» من
> `docs/reference/api-errors.md`.

| الحقل | المعنى |
|---|---|
| `type` | `urn:yemen-souq:problem:<code>` |
| `title` | عنوان عربي قصير ثابت للنوع |
| `status` | حالة HTTP |
| `detail` | رسالة عربية آمنة للمستخدم |
| `code` | رمز `snake_case` ثابت — ما يطابقه التطبيق |
| `message` | نسخة من `detail` (توافق مع العملاء القدامى و`assertJsonValidationErrors`) |
| `errors` | `{حقل: [رسائل]}` — يغيب إن لم توجد أخطاء حقول |
| `retry_after` | ثوانٍ قبل إعادة المحاولة (429 أو أي ردّ بترويسة `Retry-After`) |
| `instance` | مسار الطلب |

## الكود لا يسكن هنا

الكود في **`app/Modules/Shared/Http/Problem/`** لا في `app/Support`: `Shared` هي الوحدة
التي تعتمد عليها كل وحدة، ومجلد `app/Support` خارج عقد الوحدات
(`tests/Unit/Architecture/ModuleContractTest`). هذا الملف مرجع سريع فقط.

| الصنف | الدور |
|---|---|
| `ProblemCode` (enum) | الرموز والعناوين والرسائل والحالات الافتراضية |
| `ApiException` | **ما يُرمى من متحكّم أو خدمة** |
| `ApiProblemException` | الأم: `errors` متعددة الحقول وترويسات |
| `ProvidesProblemCode` | لصنف فرعي من `ValidationException` بنيّة رمز مخصّص |
| `ProblemDetails` | يحوّل أي استثناء إلى الاستجابة (يُستدعى من `bootstrap/app.php`) |

## كيف تُصدر خطأً

```php
use App\Modules\Shared\Http\Problem\ApiException;
use App\Modules\Shared\Http\Problem\ProblemCode;

// ApiException(ProblemCode|string $code, int $status, ?string $field, string $message, array $headers = [])
throw new ApiException(ProblemCode::PhoneTaken, 422, 'phone', 'الرقم مستخدم.');   // errors.phone
throw new ApiException('phone_not_verified', 403, null, 'وثّق رقمك أولاً.');       // بلا errors
```

الرموز المحجوزة لتدفّق الدخول، كل واحد بمصنِّع يثبّت حالته وحقله:

| المصنِّع | الرمز | الحالة | الموضع |
|---|---|---|---|
| `ApiException::invalidCredentials()` | `invalid_credentials` | 422 | `errors.password` |
| `ApiException::phoneTaken()` | `phone_taken` | 422 | `errors.phone` |
| `ApiException::invalidCode()` | `invalid_code` | 422 | `errors.code` |
| `ApiException::tooManyAttempts(int $retryAfter, ?string $field = null)` | `too_many_attempts` | 429 | `retry_after` |

`ValidationException` تتحوّل **تلقائياً** إلى `validation_failed` مع `errors` — لا تغيير في أي
متحكّم قائم.

التفصيل الكامل وجدول الرموز: `docs/reference/api-errors.md`.
