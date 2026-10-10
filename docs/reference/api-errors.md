# أخطاء الـAPI — RFC 9457

> **محروس بـ** `tests/Feature/Shared/ApiProblemDetailsTest` و`tests/Unit/Shared/ProblemCodeTest`.
> المرجع: [RFC 9457 — Problem Details for HTTP APIs](https://www.rfc-editor.org/rfc/rfc9457).

كل خطأ يخرج من مسار يبدأ بـ`/api/` له **شكل واحد ونوع محتوى واحد**:

```
Content-Type: application/problem+json
```

صفحات الويب لا تتأثر: تبقى بصفحات الأخطاء وإعادة التوجيه مع أخطاء الجلسة.

الفحص بالمسار (`/api/*`) لا بترويسة `Accept`. خطأ 404 على مسار `/api` غير موجود لا يمرّ
بوسيط `ForceJsonResponse` لأنه لا مسار مطابق فلا وسطاء، فالاعتماد على الترويسة يعيد HTML في
الحالة التي يحتاج فيها العميل JSON.

---

## الحقول

| الحقل | النوع | المعنى |
|---|---|---|
| `type` | نص | `urn:yemen-souq:problem:<code>` — مُعرِّف نوع المشكلة (RFC 9457 §3.1.1). ليس رابطاً مقصود الفتح. |
| `title` | نص | عنوان عربي قصير **ثابت للنوع**: لا يتغيّر بين حدثين من النوع نفسه. |
| `status` | رقم | حالة HTTP نفسها. |
| `detail` | نص | رسالة عربية آمنة تُعرض للمستخدم. لا تحمل تفصيلاً داخلياً أبداً. |
| `code` | نص | رمز `snake_case` ثابت — **ما يطابقه التطبيق**. لا يُطابَق نصّ عربي أبداً. |
| `message` | نص | نسخة مطابقة لـ`detail`. للتوافق مع العملاء الحاليين ومع `assertJsonValidationErrors` في لارافل. |
| `errors` | كائن | `{ "الحقل": ["رسالة", …] }`. **يغيب** حين لا أخطاء حقول (لا كائن فارغ). |
| `instance` | نص | مسار الطلب الذي فشل، بلا معاملات الرابط. |
| `retry_after` | رقم | ثوانٍ قبل إعادة المحاولة. يظهر مع 429 ومع أي خطأ يحمل ترويسة `Retry-After`. |
| `debug` | كائن | **مع `APP_DEBUG=true` وحالة 5xx فقط**: اسم الاستثناء ورسالته وأثره. لا يظهر في الإنتاج أبداً. |

الأعضاء الخمسة الأولى مع `instance` هي أعضاء RFC 9457 القياسية. أما `code` و`message`
و`errors` و`retry_after` فأعضاء امتداد (§3.2).

### التوافق مع العملاء الحاليين

قبل هذا المعالج كان الجسد `{ "message", "code"? }` وللتحقق `{ "message", "errors" }`.
**كل الحقول القديمة باقية بمعناها**، والجديد إضافة. الاستثناء الوحيد أن `message` في
الأخطاء العامة (404، 403، 500) صارت عربية ثابتة بعد أن كانت نصّ لارافل الإنجليزي — وهذا
المقصود: نصّ مثل «No query results for model [App\Models\Listing]» يكشف اسم الصنف.

---

## جدول الرموز

### عامّة

| `code` | الحالة | المعنى |
|---|---|---|
| `validation_failed` | 422 | بيانات الطلب غير صالحة. التفاصيل في `errors`. |
| `unauthenticated` | 401 | لا رمز، أو رمز منتهٍ/مسحوب. |
| `forbidden` | 403 | مصادَق لكن لا صلاحية. |
| `not_found` | 404 | المورد غير موجود (ومنه ربط النموذج بالمسار). |
| `rate_limited` | 429 | تجاوز حدّ المعدّل. مع `Retry-After` و`retry_after`. |
| `server_error` | 500 | أي خطأ غير متوقع، ورسالته عامة دائماً. |

### الدخول والتسجيل والتوثيق

| `code` | الحالة | الحقل | المعنى |
|---|---|---|---|
| `invalid_credentials` | 422 | `errors.password` | بيانات الدخول غير صحيحة. |
| `phone_taken` | 422 | `errors.phone` | رقم الجوال مستخدم في حساب آخر. |
| `invalid_code` | 422 | `errors.code` | رمز التحقق خاطئ. |
| `invalid_verification_code` | 422 | — | مرادف أولي لـ`invalid_code` (انظر الملاحظة أدناه). |
| `verification_code_expired` | 422 | — | انتهت صلاحية الرمز؛ يُطلب رمز جديد. |
| `too_many_attempts` | 429 | — | محاولات كثيرة. مع `retry_after`. |
| `resend_too_soon` | 429 | — | إعادة الإرسال قبل انقضاء المهلة. مع `retry_after`. |

`invalid_credentials` بـ422 لا 401 عن قصد: الدخول الخاطئ يعود اليوم `ValidationException`
(تحت `errors`) والتطبيقات المنشورة تعالج 422 كخطأ حقل. تغيير الحالة يكسرها قبل أن تُحدَّث.

> **ملاحظة:** `invalid_code` و`invalid_verification_code` رمزان لمعنى واحد وُرِدا من مصدرين.
> يبقيان معاً إلى أن يقرّر مالك تدفّق التوثيق أيّهما يُعتمد.

### نقل HTTP — يولّدها المعالج وحده

| `code` | الحالة |
|---|---|
| `bad_request` | 400 |
| `method_not_allowed` | 405 (مع ترويسة `Allow`) |
| `conflict` | 409 |
| `payload_too_large` | 413 |
| `unsupported_media_type` | 415 |
| `page_expired` | 419 |
| `service_unavailable` | 503 |
| `request_failed` | أي 4xx آخر |
| `server_error` | أي 5xx آخر |

### رموز نطاقية خاصة بميزة

الرمز قد يكون نصّاً خارج الـenum (مثل `phone_not_verified` و`question_not_allowed`)
بشرط الصيغة `snake_case`. عنوانه حينئذٍ عنوان حالته.

---

## أمثلة

### خطأ تحقّق — 422

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/problem+json
```

```json
{
  "type": "urn:yemen-souq:problem:validation_failed",
  "title": "البيانات غير صالحة",
  "status": 422,
  "detail": "حقل العنوان مطلوب. (وخطأ آخر)",
  "code": "validation_failed",
  "message": "حقل العنوان مطلوب. (وخطأ آخر)",
  "errors": {
    "title": ["حقل العنوان مطلوب."],
    "price": ["السعر يجب أن يكون رقماً."]
  },
  "instance": "/api/v1/me/listings"
}
```

`detail` أول رسالة، وعدد الباقي بالعربية. هذا ما كان العملاء يعرضونه من `message`.

### تجاوز حدّ المعدّل — 429

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json
Retry-After: 37
```

```json
{
  "type": "urn:yemen-souq:problem:too_many_attempts",
  "title": "محاولات كثيرة",
  "status": 429,
  "detail": "محاولات كثيرة. انتظر قليلاً ثم أعد المحاولة.",
  "code": "too_many_attempts",
  "message": "محاولات كثيرة. انتظر قليلاً ثم أعد المحاولة.",
  "instance": "/api/v1/auth/login",
  "retry_after": 37
}
```

### خطأ غير متوقع — 500 (`APP_DEBUG=false`)

```json
{
  "type": "urn:yemen-souq:problem:server_error",
  "title": "خطأ في الخادم",
  "status": 500,
  "detail": "حدث خطأ غير متوقع. حاول مرة أخرى بعد قليل.",
  "code": "server_error",
  "message": "حدث خطأ غير متوقع. حاول مرة أخرى بعد قليل.",
  "instance": "/api/v1/listings"
}
```

بلا أثر ولا اسم استثناء ولا رسالته. ورسائل `abort(404, '…')` المخصّصة لا تخرج أيضاً: النصّ
الثابت من `ProblemCode` هو ما يصل العميل.

---

## من الخادم: كيف تُصدر خطأً

```php
use App\Modules\Shared\Http\Problem\ApiException;
use App\Modules\Shared\Http\Problem\ProblemCode;

// (code, status, field?, message) — field غير null يضع errors.<field>
throw new ApiException(ProblemCode::PhoneTaken, 422, 'phone', 'الرقم مستخدم.');

// مصنِّعات الرموز المحجوزة للدخول والتسجيل — الحقل والحالة مثبَّتان
throw ApiException::invalidCredentials();     // 422، errors.password
throw ApiException::phoneTaken();             // 422، errors.phone
throw ApiException::invalidCode();            // 422، errors.code
throw ApiException::tooManyAttempts(60);      // 429، Retry-After + retry_after
```

وللحالات التي تحتاج `errors` متعددة الحقول أو ترويسات: `ApiProblemException`
(الصنف الأم) بتوقيع `(code, status, detail, errors = [], headers = [], title = null)`
ومعه `ApiProblemException::field($code, $field, $message, $status = 422)` و
`ApiProblemException::of(ProblemCode, ?detail, errors, headers, ?status)`.

- `ValidationException` تتحوّل **تلقائياً** إلى `validation_failed` مع `errors`، ولا حاجة لتغيير
  أي متحكّم. ومن يحتاج رمزاً آخر يجعل صنفاً فرعياً منها ينفّذ `ProvidesProblemCode`.
- أخطاء العميل (4xx) من `ApiProblemException` **لا تُسجَّل** في السجلّ؛ الـ5xx تُسجَّل.
- في صفحات الويب يتدهور الاستثناء بلا كسر: أخطاء الحقول تعود إعادة توجيه مع أخطاء
  الجلسة، وغيرها صفحة الحالة المعتادة.

## من العميل: `resources/js/http.js`

```js
import { http, fieldErrors } from './http.js';

const res = await http.post('/api/v1/auth/login', { identifier, password });

if (res.ok) {
    token = res.data.token;
} else if (res.problem.code === 'too_many_attempts') {
    wait(res.problem.retry_after);
} else {
    errors = fieldErrors(res.problem);   // { password: 'البيانات غير صحيحة.' }
}
```

الغلاف لا يرمي أبداً: يعيد `{ ok, status, data, problem }` نجاحاً وفشلاً. وانقطاع الشبكة أو ردّ
HTML من وسيط يصيران `problem` بنفس الشكل (`status: 0` و`code: "network_error"` للأول).

---

## ما لا يغطّيه المعالج بعد

المعالج يعمل على **الاستثناءات**. ما يُبنى استجابةً جاهزة (`return response()->json(…, 4xx)`)
لا يمرّ به ويبقى بشكله القديم، ومنها ردّ حدّ المعدّل في `ApiServiceProvider::tooMany` و
`Limit::response`. قائمتها وتحويلها خطوة لاحقة، وأي رمز منها (`phone_not_verified`،
`question_not_allowed`…) يبقى كما هو إلى أن يُحوَّل.
