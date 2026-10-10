<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Http\Problem\ProblemCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * الـenum هو العقد: ما يُحذف أو يُعاد تسميته منه يكسر تطبيقاً منشوراً.
 */
class ProblemCodeTest extends TestCase
{
    /** الرموز التي وعدت بها الموجة التالية — حذف أيٍّ منها يفشل هنا */
    private const REQUIRED = [
        'validation_failed', 'unauthenticated', 'forbidden', 'not_found',
        'rate_limited', 'server_error', 'invalid_credentials', 'phone_taken',
        'invalid_verification_code', 'verification_code_expired',
        'too_many_attempts', 'resend_too_soon', 'invalid_code',
    ];

    #[Test]
    public function the_promised_codes_exist(): void
    {
        $values = array_map(fn (ProblemCode $c) => $c->value, ProblemCode::cases());

        $this->assertSame([], array_diff(self::REQUIRED, $values));
    }

    #[Test]
    public function every_code_is_snake_case_unique_and_fully_described(): void
    {
        $seen = [];

        foreach (ProblemCode::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]*(_[a-z0-9]+)*$/', $case->value);
            $this->assertArrayNotHasKey($case->value, $seen);
            $seen[$case->value] = true;

            // عربي: كل عنوان ورسالة تحوي حرفاً عربياً
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $case->title(), $case->value);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $case->defaultDetail(), $case->value);

            $this->assertGreaterThanOrEqual(400, $case->defaultStatus());
            $this->assertLessThan(600, $case->defaultStatus());

            $this->assertSame('urn:yemen-souq:problem:'.$case->value, ProblemCode::typeFor($case->value));
        }
    }

    #[Test]
    public function default_statuses_of_the_login_flow_codes_are_pinned(): void
    {
        $this->assertSame(422, ProblemCode::InvalidCredentials->defaultStatus());
        $this->assertSame(422, ProblemCode::PhoneTaken->defaultStatus());
        $this->assertSame(422, ProblemCode::InvalidCode->defaultStatus());
        $this->assertSame(429, ProblemCode::TooManyAttempts->defaultStatus());
        $this->assertSame(429, ProblemCode::ResendTooSoon->defaultStatus());
        $this->assertSame(429, ProblemCode::RateLimited->defaultStatus());
    }

    #[Test]
    public function for_status_maps_http_statuses(): void
    {
        $this->assertSame(ProblemCode::NotFound, ProblemCode::forStatus(404));
        $this->assertSame(ProblemCode::Forbidden, ProblemCode::forStatus(403));
        $this->assertSame(ProblemCode::RateLimited, ProblemCode::forStatus(429));
        $this->assertSame(ProblemCode::ServerError, ProblemCode::forStatus(500));
        $this->assertSame(ProblemCode::ServerError, ProblemCode::forStatus(504));
        $this->assertSame(ProblemCode::RequestFailed, ProblemCode::forStatus(418));
    }
}
