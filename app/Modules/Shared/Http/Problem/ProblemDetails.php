<?php

namespace App\Modules\Shared\Http\Problem;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * يحوّل أي استثناء في ‎/api/*‎ إلى استجابة RFC 9457 (Problem Details).
 *
 * ═══ الصيغة ═══
 *
 * ```
 * Content-Type: application/problem+json
 * { type, title, status, detail, code, message, errors?, instance, retry_after? }
 * ```
 *
 * RFC 9457 §3.1 يعرّف `type` و`title` و`status` و`detail` و`instance`، ويسمح
 * بأعضاء امتداد (§3.2). والامتدادات هنا ثلاثة، وكلها لسبب:
 *
 * - `code`: الرمز الذي يطابقه التطبيق. `type` يحمله أيضاً لكنه URN يحتاج
 *   تقطيعاً، و`code` هو العقد الموثّق في docs/architecture/api.md.
 * - `message`: نسخة من `detail`. لارافل (`assertJsonValidationErrors`) والعملاء
 *   المنشورون يقرؤون `message`، وإسقاطه كسرٌ بلا فائدة.
 * - `errors`: `{field: [messages]}` — شكل لارافل القياسي لأخطاء التحقق.
 *
 * ═══ متى يعمل ═══
 *
 * فقط `$request->is('api/*')` — صفحات الويب تبقى بصفحات لارافل وإعادة
 * التوجيه. والفحص بالمسار لا بترويسة `Accept`: خطأ 404 على مسار ‎/api‎
 * غير موجود لا يمرّ بوسيط `ForceJsonResponse` (لا مسار مطابق فلا وسطاء)،
 * فمن يعتمد على الترويسة يعيد إليه HTML في أشدّ الحالات التي يحتاج فيها JSON.
 *
 * ═══ ما لا يخرج أبداً ═══
 *
 * رسالة الاستثناء الخام لا تصل العميل إلا في ثلاث حالات صريحة: رسائل
 * التحقق، و`ApiProblemException` (كاتبها قصد إظهارها)، وحالة التصحيح
 * (`app.debug`) في حقل `debug` منفصل. أيّ استثناء آخر — وكذلك رسائل
 * `abort(404, '…')` — يخرج بنصّ عربي ثابت من `ProblemCode`: رسالة
 * «No query results for model [App\Models\Listing]» تكشف اسم الصنف.
 */
final class ProblemDetails
{
    public const CONTENT_TYPE = 'application/problem+json';

    /** هل يتولّى هذا الصنف الطلب؟ */
    public static function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    /**
     * الاستجابة، أو null حين لا يخصّه الطلب (ويب) أو يملك الاستثناء استجابته.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::handles($request)) {
            return null;
        }

        // استجابة جاهزة صنعها من ألقاه عن قصد (إعادة توجيه، تنزيل…)
        if ($e instanceof HttpResponseException) {
            return null;
        }

        return self::respond(self::describe($e), $request, $e);
    }

    /**
     * `ApiProblemException` خارج ‎/api‎: خدمة واحدة قد تُستدعى من الويب
     * ومن الـAPI، ويجب ألا تنقلب في الويب إلى صفحة 500.
     *
     * أخطاء الحقول تعود ValidationException (إعادة توجيه مع الأخطاء
     * كأي نموذج)، وما عداها HttpException بحالته.
     */
    public static function forWeb(ApiProblemException $e): Throwable
    {
        if ($e->errors !== []) {
            return ValidationException::withMessages($e->errorBag())->status($e->status);
        }

        return new HttpException($e->status, $e->getMessage(), $e, $e->headers);
    }

    /**
     * @return array{code: string, status: int, detail: string, title: string, errors: array<string, list<string>>, headers: array<string, string>}
     */
    private static function describe(Throwable $e): array
    {
        if ($e instanceof ApiProblemException) {
            $enum = ProblemCode::tryFrom($e->problemCode);

            return [
                'code' => $e->problemCode,
                'status' => $e->status,
                'detail' => $e->getMessage(),
                'title' => $e->title ?? ($enum ?? ProblemCode::forStatus($e->status))->title(),
                'errors' => $e->errorBag(),
                'headers' => $e->headers,
            ];
        }

        if ($e instanceof ValidationException) {
            $code = $e instanceof ProvidesProblemCode ? $e->problemCode() : ProblemCode::ValidationFailed;
            $code = $code instanceof ProblemCode ? $code : (ProblemCode::tryFrom($code) ?? $code);
            $errors = self::normalize($e->errors());

            return [
                'code' => $code instanceof ProblemCode ? $code->value : $code,
                'status' => $e->status,
                'detail' => self::validationDetail($errors),
                'title' => ($code instanceof ProblemCode ? $code : ProblemCode::ValidationFailed)->title(),
                'errors' => $errors,
                'headers' => [],
            ];
        }

        if ($e instanceof ModelNotFoundException) {
            return self::fromCode(ProblemCode::NotFound, 404);
        }

        if ($e instanceof AuthenticationException) {
            return self::fromCode(ProblemCode::Unauthenticated, 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::fromCode(ProblemCode::Forbidden, 403);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return self::fromCode(ProblemCode::forStatus($status), $status, $e->getHeaders());
        }

        return self::fromCode(ProblemCode::ServerError, 500);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array{code: string, status: int, detail: string, title: string, errors: array<string, list<string>>, headers: array<string, string>}
     */
    private static function fromCode(ProblemCode $code, int $status, array $headers = []): array
    {
        return [
            'code' => $code->value,
            'status' => $status,
            'detail' => $code->defaultDetail(),
            'title' => $code->title(),
            'errors' => [],
            'headers' => $headers,
        ];
    }

    /**
     * @param  array{code: string, status: int, detail: string, title: string, errors: array<string, list<string>>, headers: array<string, string>}  $problem
     */
    private static function respond(array $problem, Request $request, Throwable $e): JsonResponse
    {
        $body = [
            'type' => ProblemCode::typeFor($problem['code']),
            'title' => $problem['title'],
            'status' => $problem['status'],
            'detail' => $problem['detail'],
            'code' => $problem['code'],
            // نسخة من detail — انظر ترويسة الصنف
            'message' => $problem['detail'],
        ];

        if ($problem['errors'] !== []) {
            $body['errors'] = $problem['errors'];
        }

        $body['instance'] = '/'.ltrim($request->path(), '/');

        $retryAfter = self::retryAfter($problem['headers']);

        if ($retryAfter !== null) {
            $body['retry_after'] = $retryAfter;
        }

        if ($problem['status'] >= 500 && config('app.debug')) {
            $body['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice(
                    array_map(
                        fn (array $frame): string => ($frame['file'] ?? '[internal]').':'.($frame['line'] ?? 0),
                        $e->getTrace()
                    ),
                    0,
                    20
                ),
            ];
        }

        return new JsonResponse(
            $body,
            $problem['status'],
            // نوع المحتوى أخيراً: لا ترويسة قادمة من الاستثناء تُبدّله
            [...$problem['headers'], 'Content-Type' => self::CONTENT_TYPE],
        );
    }

    /**
     * رسالة التحقق: أول خطأ، وعدد الباقي بالعربية.
     *
     * ValidationException::summarize في لارافل تُنتج الشكل نفسه لكن
     * بملحق إنجليزي «(and 2 more errors)» ما لم تُترجَم، ولا ملفات لغة هنا.
     * والمحافظة على «أول خطأ» هي ما يعرضه العملاء الحاليون من `message`.
     *
     * @param  array<string, list<string>>  $errors
     */
    private static function validationDetail(array $errors): string
    {
        $messages = array_merge(...array_values($errors ?: [[]]));

        if ($messages === []) {
            return ProblemCode::ValidationFailed->defaultDetail();
        }

        $rest = count($messages) - 1;

        return match (true) {
            $rest === 0 => $messages[0],
            $rest === 1 => $messages[0].' (وخطأ آخر)',
            default => $messages[0]." (و{$rest} أخطاء أخرى)",
        };
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, list<string>>
     */
    private static function normalize(array $errors): array
    {
        return array_map(fn (array $messages): array => array_values($messages), $errors);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function retryAfter(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Retry-After') === 0 && is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }
}
