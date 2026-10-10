<?php

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Shared\Http\Problem\ApiException;
use App\Modules\Shared\Http\Problem\ApiProblemException;
use App\Modules\Shared\Http\Problem\ProblemCode;
use App\Modules\Shared\Http\Problem\ProvidesProblemCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * معالج أخطاء ‎/api/*‎ بصيغة RFC 9457.
 *
 * كل اختبار يسجّل مساراً مؤقتاً يُلقي الاستثناء المقصود: نختبر المعالج لا
 * متحكّماً بعينه، فلا يكسره تغيّر متحكّم. والمسارات الحقيقية تغطيها
 * اختبارات ‎Api/‎ القائمة (وهي تمرّ الآن عبر المعالج نفسه).
 */
class ApiProblemDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // الأساس: لا تصحيح. ما يتعلّق بـdebug يضبطه اختباره صراحةً
        config(['app.debug' => false]);
    }

    /** مسار api مؤقت يُنفّذ الدالة */
    private function api(string $method, string $uri, callable $action, array $middleware = []): string
    {
        Route::middleware(['api', ...$middleware])->prefix('api')->match([$method], $uri, $action);

        return '/api/'.$uri;
    }

    private function web(string $uri, callable $action): string
    {
        Route::middleware('web')->get($uri, $action);

        return '/'.$uri;
    }

    /**
     * العقد المشترك لكل خطأ: النوع والترويسة وتطابق message مع detail.
     */
    private function assertProblem(TestResponse $response, int $status, string $code): array
    {
        $response->assertStatus($status);

        $this->assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));

        $body = json_decode($response->getContent(), true);

        $this->assertSame("urn:yemen-souq:problem:{$code}", $body['type']);
        $this->assertSame($code, $body['code']);
        $this->assertSame($status, $body['status']);
        $this->assertIsString($body['title']);
        $this->assertNotSame('', $body['title']);
        $this->assertIsString($body['detail']);
        $this->assertSame($body['detail'], $body['message'], 'message نسخة مطابقة لـ detail');
        $this->assertIsString($body['instance']);
        $this->assertStringStartsWith('/api/', $body['instance']);

        return $body;
    }

    // ─── التحويلات ───

    #[Test]
    public function validation_exception_becomes_422_validation_failed(): void
    {
        $uri = $this->api('POST', '_probe/validate', fn (Request $r) => $r->validate([
            'title' => ['required'],
            'price' => ['required', 'numeric'],
        ]));

        $response = $this->postJson($uri, ['price' => 'abc']);

        $body = $this->assertProblem($response, 422, 'validation_failed');

        // الشكلان معاً: مفتاح errors الذي يقرؤه التطبيق ويفحصه لارافل
        $response->assertJsonValidationErrors(['title', 'price']);
        $this->assertIsArray($body['errors']['title']);
        $this->assertIsString($body['errors']['title'][0]);
        $this->assertArrayHasKey('message', $body);
        $this->assertStringContainsString('(وخطأ آخر)', $body['message']);
    }

    #[Test]
    public function validation_message_is_the_first_error_alone_when_it_is_the_only_one(): void
    {
        $uri = $this->api('POST', '_probe/one', function () {
            throw ValidationException::withMessages(['phone' => 'رقم الجوال غير صالح.']);
        });

        $body = $this->assertProblem($this->postJson($uri), 422, 'validation_failed');

        $this->assertSame('رقم الجوال غير صالح.', $body['message']);
        $this->assertSame(['phone' => ['رقم الجوال غير صالح.']], $body['errors']);
    }

    #[Test]
    public function a_validation_exception_can_carry_its_own_code(): void
    {
        $uri = $this->api('POST', '_probe/custom', function () {
            throw new class(validator([], []), null) extends ValidationException implements ProvidesProblemCode
            {
                public function __construct($validator, $response)
                {
                    parent::__construct($validator, $response);
                }

                public function errors()
                {
                    return ['phone' => ['الرقم مستخدم.']];
                }

                public function problemCode(): ProblemCode|string
                {
                    return ProblemCode::PhoneTaken;
                }
            };
        });

        $body = $this->assertProblem($this->postJson($uri), 422, 'phone_taken');

        $this->assertSame(['phone' => ['الرقم مستخدم.']], $body['errors']);
    }

    #[Test]
    public function authentication_exception_becomes_401_unauthenticated(): void
    {
        // مسار حقيقي محميّ بـ auth:sanctum — لا رمز
        $this->assertProblem($this->getJson('/api/v1/auth/me'), 401, 'unauthenticated');
    }

    #[Test]
    public function authentication_failure_is_json_even_without_accept_header(): void
    {
        $response = $this->call('GET', '/api/v1/auth/me');

        $this->assertProblem($response, 401, 'unauthenticated');
    }

    #[Test]
    public function authorization_exception_becomes_403_forbidden(): void
    {
        $uri = $this->api('GET', '_probe/authz', function () {
            throw new AuthorizationException('هذا نصّ داخلي لا يصل العميل');
        });

        $body = $this->assertProblem($this->getJson($uri), 403, 'forbidden');

        $this->assertStringNotContainsString('داخلي', $body['detail']);
    }

    #[Test]
    public function any_403_abort_becomes_forbidden(): void
    {
        $uri = $this->api('GET', '_probe/abort403', fn () => abort(403, 'secret reason'));

        $body = $this->assertProblem($this->getJson($uri), 403, 'forbidden');

        $this->assertStringNotContainsString('secret', $body['detail']);
    }

    #[Test]
    public function model_not_found_becomes_404_without_leaking_the_model_class(): void
    {
        $uri = $this->api('GET', '_probe/model/{user}', fn (User $user) => $user->id);

        $response = $this->getJson(str_replace('{user}', '999999', $uri));

        $this->assertProblem($response, 404, 'not_found');
        $this->assertStringNotContainsString('App\\\\Models', $response->getContent());
        $this->assertStringNotContainsString('No query results', $response->getContent());
    }

    #[Test]
    public function a_thrown_model_not_found_exception_becomes_404(): void
    {
        $uri = $this->api('GET', '_probe/mnf', function () {
            throw (new ModelNotFoundException)->setModel(User::class, [1]);
        });

        $this->assertProblem($this->getJson($uri), 404, 'not_found');
    }

    #[Test]
    public function an_unknown_api_path_is_a_404_problem_not_html(): void
    {
        // لا مسار مطابق فلا وسطاء: ForceJsonResponse لم يعمل هنا
        $this->assertProblem($this->call('GET', '/api/v1/no-such-endpoint'), 404, 'not_found');
    }

    #[Test]
    public function abort_404_with_a_custom_message_does_not_leak_it(): void
    {
        $uri = $this->api('GET', '_probe/abort404', fn () => abort(404, 'row 17 of table users missing'));

        $response = $this->getJson($uri);

        $this->assertProblem($response, 404, 'not_found');
        $this->assertStringNotContainsString('table users', $response->getContent());
    }

    #[Test]
    public function throttle_exception_becomes_429_rate_limited_with_retry_after(): void
    {
        $uri = $this->api('GET', '_probe/throttled', function () {
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 37]);
        });

        $response = $this->getJson($uri);

        $body = $this->assertProblem($response, 429, 'rate_limited');

        $this->assertSame('37', $response->headers->get('Retry-After'));
        $this->assertSame(37, $body['retry_after']);
    }

    #[Test]
    public function the_real_throttle_middleware_is_converted_too(): void
    {
        $uri = $this->api('GET', '_probe/limited', fn () => ['ok' => true], ['throttle:1,1']);

        $this->getJson($uri)->assertOk();

        $response = $this->getJson($uri);

        $body = $this->assertProblem($response, 429, 'rate_limited');

        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertIsInt($body['retry_after']);
        $this->assertGreaterThan(0, $body['retry_after']);
    }

    #[Test]
    public function other_http_exceptions_map_by_status(): void
    {
        $get = $this->api('GET', '_probe/get-only', fn () => ['ok' => true]);

        $response = $this->postJson($get);
        $this->assertProblem($response, 405, 'method_not_allowed');
        $this->assertNotNull($response->headers->get('Allow'), 'ترويسات الاستثناء تُحفظ');

        foreach ([
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            503 => 'service_unavailable',
            400 => 'bad_request',
            418 => 'request_failed',
            502 => 'server_error',
        ] as $status => $code) {
            $uri = $this->api('GET', "_probe/s{$status}", fn () => abort($status, 'internal detail'));

            $response = $this->getJson($uri);
            $body = $this->assertProblem($response, $status, $code);

            $this->assertStringNotContainsString('internal detail', $response->getContent());
            $this->assertSame($body['detail'], ProblemCode::from($code)->defaultDetail());
        }
    }

    #[Test]
    public function csrf_mismatch_becomes_419_page_expired(): void
    {
        $uri = $this->api('POST', '_probe/csrf', function () {
            throw new TokenMismatchException('CSRF token mismatch.');
        });

        $this->assertProblem($this->postJson($uri), 419, 'page_expired');
    }

    // ─── الأخطاء غير المتوقعة ───

    #[Test]
    public function unexpected_exceptions_become_a_generic_500_with_no_leak_when_debug_is_off(): void
    {
        $uri = $this->api('GET', '_probe/boom', function () {
            throw new RuntimeException('SQLSTATE[HY000]: secret-table-name connection refused');
        });

        $response = $this->getJson($uri);

        $body = $this->assertProblem($response, 500, 'server_error');

        $this->assertSame(ProblemCode::ServerError->defaultDetail(), $body['detail']);

        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('debug', $body);
        $this->assertArrayNotHasKey('exception', $body);
        $this->assertArrayNotHasKey('file', $body);

        $raw = $response->getContent();

        foreach (['RuntimeException', 'secret-table-name', 'SQLSTATE', 'ApiProblemDetailsTest', 'vendor', 'trace', '.php'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "تسرّب [{$needle}]");
        }
    }

    #[Test]
    public function a_500_http_exception_is_generic_too(): void
    {
        $uri = $this->api('GET', '_probe/http500', fn () => abort(500, 'db password is hunter2'));

        $response = $this->getJson($uri);

        $this->assertProblem($response, 500, 'server_error');
        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    #[Test]
    public function debug_mode_adds_a_separate_debug_member_but_keeps_the_envelope(): void
    {
        config(['app.debug' => true]);

        $uri = $this->api('GET', '_probe/boom-debug', function () {
            throw new RuntimeException('visible only in debug');
        });

        $body = $this->assertProblem($this->getJson($uri), 500, 'server_error');

        // detail يبقى عاماً حتى في التصحيح — التفصيل في debug وحده
        $this->assertSame(ProblemCode::ServerError->defaultDetail(), $body['detail']);
        $this->assertSame(RuntimeException::class, $body['debug']['exception']);
        $this->assertSame('visible only in debug', $body['debug']['message']);
    }

    #[Test]
    public function debug_member_never_appears_on_client_errors(): void
    {
        config(['app.debug' => true]);

        $body = $this->assertProblem($this->call('GET', '/api/v1/no-such-endpoint'), 404, 'not_found');

        $this->assertArrayNotHasKey('debug', $body);
    }

    // ─── الاستثناء النطاقي ───

    #[Test]
    public function the_domain_exception_field_shortcut_gives_422_with_code_and_errors(): void
    {
        $uri = $this->api('POST', '_probe/taken', function () {
            throw ApiProblemException::field('phone_taken', 'phone', 'رقم الجوال مستخدم.');
        });

        $body = $this->assertProblem($this->postJson($uri), 422, 'phone_taken');

        $this->assertSame(['phone' => ['رقم الجوال مستخدم.']], $body['errors']);
        $this->assertSame('رقم الجوال مستخدم.', $body['detail']);
        $this->assertSame(ProblemCode::PhoneTaken->title(), $body['title']);
    }

    #[Test]
    public function the_domain_exception_carries_status_errors_and_headers(): void
    {
        $uri = $this->api('POST', '_probe/domain', function () {
            throw new ApiProblemException(
                'phone_not_verified',
                403,
                'وثّق رقمك أولاً.',
                ['phone' => 'غير موثّق'],
                ['Retry-After' => '12', 'X-Custom' => 'yes'],
            );
        });

        $response = $this->postJson($uri);

        $body = $this->assertProblem($response, 403, 'phone_not_verified');

        $this->assertSame('yes', $response->headers->get('X-Custom'));
        $this->assertSame(12, $body['retry_after']);
        $this->assertSame(['phone' => ['غير موثّق']], $body['errors'], 'سلسلة مفردة تصير قائمة');
        // رمز نطاقي بلا عنوان في الـenum: عنوان الحالة
        $this->assertSame(ProblemCode::Forbidden->title(), $body['title']);
    }

    #[Test]
    public function the_domain_exception_rejects_a_malformed_code(): void
    {
        foreach (['PhoneTaken', 'phone-taken', 'phone taken', '', '_x', 'x__y'] as $bad) {
            try {
                new ApiProblemException($bad, 422, 'x');
                $this->fail("قُبل الرمز [{$bad}]");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new ApiProblemException('fine_code', 200, 'x');
    }

    // ─── الرموز الخمسة المعتمدة لتدفّق الدخول ───

    #[Test]
    public function invalid_credentials_lands_under_errors_password_only(): void
    {
        $uri = $this->api('POST', '_probe/login', function () {
            throw ApiException::invalidCredentials();
        });

        $body = $this->assertProblem($this->postJson($uri), 422, 'invalid_credentials');

        $this->assertSame(['password'], array_keys($body['errors']));
        $this->assertArrayNotHasKey('identifier', $body['errors']);
        $this->assertSame($body['errors']['password'][0], $body['message']);
    }

    #[Test]
    public function phone_taken_lands_under_errors_phone(): void
    {
        $uri = $this->api('POST', '_probe/register', function () {
            throw ApiException::phoneTaken();
        });

        $body = $this->assertProblem($this->postJson($uri), 422, 'phone_taken');

        $this->assertSame(['phone'], array_keys($body['errors']));
    }

    #[Test]
    public function invalid_code_lands_under_errors_code(): void
    {
        $uri = $this->api('POST', '_probe/verify', function () {
            throw ApiException::invalidCode();
        });

        $body = $this->assertProblem($this->postJson($uri), 422, 'invalid_code');

        $this->assertSame(['code'], array_keys($body['errors']));
    }

    #[Test]
    public function too_many_attempts_is_429_with_retry_after(): void
    {
        $uri = $this->api('POST', '_probe/attempts', function () {
            throw ApiException::tooManyAttempts(90, 'identifier');
        });

        $response = $this->postJson($uri);

        $body = $this->assertProblem($response, 429, 'too_many_attempts');

        $this->assertSame('90', $response->headers->get('Retry-After'));
        $this->assertSame(90, $body['retry_after']);
        $this->assertArrayHasKey('identifier', $body['errors']);
    }

    #[Test]
    public function too_many_attempts_without_a_field_has_no_errors_member(): void
    {
        $uri = $this->api('POST', '_probe/attempts-nofield', function () {
            throw ApiException::tooManyAttempts(5);
        });

        $body = $this->assertProblem($this->postJson($uri), 429, 'too_many_attempts');

        $this->assertArrayNotHasKey('errors', $body);
        $this->assertSame(5, $body['retry_after']);
    }

    #[Test]
    public function api_exception_takes_code_status_field_message_in_that_order(): void
    {
        $uri = $this->api('POST', '_probe/api-exception', function () {
            throw new ApiException('phone_not_verified', 403, 'phone', 'وثّق رقمك أولاً.');
        });

        $body = $this->assertProblem($this->postJson($uri), 403, 'phone_not_verified');

        $this->assertSame(['phone' => ['وثّق رقمك أولاً.']], $body['errors']);
        $this->assertSame('وثّق رقمك أولاً.', $body['message']);
    }

    // ─── التقارير ───

    #[Test]
    public function client_side_domain_errors_are_not_reported_but_server_ones_are(): void
    {
        $handler = app(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(ApiException::phoneTaken()));
        $this->assertFalse($handler->shouldReport(ApiException::tooManyAttempts(10)));
        $this->assertTrue($handler->shouldReport(new ApiProblemException(ProblemCode::ServerError, 500, 'x')));
        $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
    }

    // ─── الويب لا يتأثر ───

    #[Test]
    public function web_404_stays_an_html_page(): void
    {
        $response = $this->get('/no-such-web-page');

        $response->assertNotFound();
        $this->assertStringNotContainsString('problem+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function web_validation_still_redirects_back_with_session_errors(): void
    {
        $uri = $this->web('_probe-web/validate', fn (Request $r) => $r->validate(['q' => ['required']]));

        $response = $this->from('/somewhere')->get($uri);

        $response->assertRedirect('/somewhere');
        $response->assertSessionHasErrors('q');
    }

    #[Test]
    public function web_json_validation_keeps_laravels_standard_shape(): void
    {
        // Accept: json على مسار ويب — شكل لارافل القياسي، لا problem+json
        $uri = $this->web('_probe-web/validate-json', fn (Request $r) => $r->validate(['q' => ['required']]));

        $response = $this->getJson($uri);

        $response->assertStatus(422)->assertJsonValidationErrors('q');
        $this->assertStringNotContainsString('problem+json', (string) $response->headers->get('Content-Type'));
        $this->assertArrayNotHasKey('type', $response->json());
    }

    #[Test]
    public function web_500_is_not_converted(): void
    {
        $uri = $this->web('_probe-web/boom', function () {
            throw new RuntimeException('web boom');
        });

        $response = $this->get($uri);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('problem+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_domain_exception_degrades_gracefully_on_web(): void
    {
        $fieldUri = $this->web('_probe-web/field', function () {
            throw ApiException::phoneTaken();
        });

        $this->from('/form')->get($fieldUri)
            ->assertRedirect('/form')
            ->assertSessionHasErrors('phone');

        $plainUri = $this->web('_probe-web/plain', function () {
            throw new ApiException('forbidden', 403, null, 'ممنوع.');
        });

        $response = $this->get($plainUri);

        $response->assertForbidden();
        $this->assertStringNotContainsString('problem+json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_api_prefix_is_matched_exactly_not_by_substring(): void
    {
        // «/apiary» و«/docs/api/x» ليسا ‎/api/*‎
        $response = $this->get('/apiary');

        $response->assertNotFound();
        $this->assertStringNotContainsString('problem+json', (string) $response->headers->get('Content-Type'));

        $this->assertStringNotContainsString(
            'problem+json',
            (string) $this->get('/docs/api/x')->headers->get('Content-Type')
        );
    }
}
