<?php

namespace Tests\Feature\Api;

use App\Modules\Api\Http\Middleware\ForceJsonResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route as Router;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * عقد الـAPI محروساً باختبار لا بانضباط.
 *
 * القواعد الثلاث هنا كانت تعليقات في `ModuleServiceProvider` وملف
 * المسارات — والتعليق يُخرَق بلا أن يلاحظ أحد. وأخطرها الثالثة: مسارُ
 * كتابةٍ يُنسى خارج `auth:sanctum` لا يُنتج خطأً بل نقطةَ نهاية مفتوحة.
 */
class ApiContractTest extends TestCase
{
    /**
     * مسارات عامة بقصد — التصفّح قبل التسجيل.
     *
     * القائمة صريحة لا نمط: نمطٌ مثل «كل GET عام» يجعل إضافة نقطة
     * قراءةٍ خاصة (مثل ‎/me/listings‎) تمرّ بلا انتباه.
     */
    private const PUBLIC_ROUTES = [
        'api.v1.health',
        'api.v1.bootstrap',
        'api.v1.categories',
        'api.v1.categories.attributes',
        'api.v1.locations',
        'api.v1.currencies',
        'api.v1.report-reasons',
        'api.v1.listings.index',
        'api.v1.listings.show',
        'api.v1.listings.similar',
        'api.v1.listings.questions',
        'api.v1.listings.contact',
        'api.v1.auth.register',
        'api.v1.auth.login',
        'api.v1.auth.password.forgot',
        'api.v1.auth.password.reset',
    ];

    /**
     * الوسائط التي تجعل الطلب ذا حالة.
     *
     * وجود أيٍّ منها على مسار API يعني أن الطلب يفتح جلسة ويطلب رمز
     * CSRF — فيُرفض كل POST من تطبيق يحمل رمزاً في الترويسة بـ419، بلا
     * سبب ظاهر في الاستجابة.
     */
    private const STATEFUL_MIDDLEWARE = [
        StartSession::class,
        ValidateCsrfToken::class,
        EncryptCookies::class,
        'web',
    ];

    #[Test]
    public function no_api_route_is_stateful(): void
    {
        $violations = [];

        foreach ($this->apiRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            foreach (self::STATEFUL_MIDDLEWARE as $stateful) {
                if (in_array($stateful, $middleware, true)) {
                    $violations[] = $route->uri().' -> '.$stateful;
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['مسارات API بلا حالة: مجموعة web تفتح جلسة وتطلب CSRF.'],
            $violations
        )));
    }

    /**
     * كل مسار API يمرّ من ForceJsonResponse.
     *
     * بلاه يقرّر لارافل شكل الخطأ من ترويسة Accept: عميلٌ نسيها يستقبل
     * صفحة HTML لخطأ 422 بينما المسار الناجح يعطيه JSON — فيقرأ التطبيق
     * النجاح ويختنق عند الفشل.
     */
    #[Test]
    public function every_api_route_forces_json(): void
    {
        $missing = [];

        foreach ($this->apiRoutes() as $route) {
            if (! in_array(ForceJsonResponse::class, $route->gatherMiddleware(), true)) {
                $missing[] = $route->uri();
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['مسارات لا تُجبر JSON — أخطاؤها ستعود HTML:'],
            $missing
        )));
    }

    /**
     * أخطر قاعدة: ما ليس في القائمة البيضاء يحتاج حارساً.
     *
     * مسارُ كتابةٍ يُنسى خارج الحراسة لا يُفشل شيئاً — يعمل، ويُقبل من
     * أي أحد. القائمة الصريحة تجعل كل نقطة نهاية جديدة تمرّ من هنا قبل
     * أن تُنشر.
     *
     * ═══ ولماذا «حارس» لا `auth:sanctum` وحده ═══
     *
     * القاعدة كانت تسمّي `auth:sanctum` حصراً. ثم ظهرت وحدة تحمل مسارات
     * لمستهلكٍ **ليس شخصاً** (عامل الرسائل على الهاتف)، فحارسها سرٌّ ثابت
     * لا رمزٌ شخصي — والسبب في `AuthenticateSmsWorker`.
     *
     * والحلّ اصطلاح تسمية لا قائمة أسماء وحدات: أي وسيط يبدأ اسمه
     * بـ`Authenticate` يُعدّ حارساً. فالاختبار يبقى صحيحاً حين تُحذف
     * تلك الوحدة — وقاعدةٌ تذكر وحدةً باسمها تتقادم بحذفها.
     */
    #[Test]
    public function every_route_outside_the_public_list_is_guarded(): void
    {
        $unguarded = [];

        foreach ($this->apiRoutes() as $route) {
            $name = (string) $route->getName();

            if (in_array($name, self::PUBLIC_ROUTES, true)) {
                continue;
            }

            if (! $this->isGuarded($route)) {
                $unguarded[] = $route->methods()[0].' '.$route->uri().' ('.$name.')';
            }
        }

        $this->assertSame([], $unguarded, implode("\n", array_merge(
            ['نقاط نهاية بلا حارس وليست معلَنة عامة:'],
            $unguarded,
            ['', 'إمّا تُحرَس، أو تُضاف إلى PUBLIC_ROUTES بقصد صريح.']
        )));
    }

    private function isGuarded(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth:sanctum') {
                return true;
            }

            if (str_starts_with(class_basename($middleware), 'Authenticate')) {
                return true;
            }
        }

        return false;
    }

    /** والعكس: قائمةٌ بيضاء لا تتقادم — اسمٌ فيها لمسار لم يعد موجوداً */
    #[Test]
    public function the_public_list_has_no_stale_entries(): void
    {
        $names = array_map(
            static fn (Route $route): string => (string) $route->getName(),
            $this->apiRoutes()
        );

        $stale = array_values(array_diff(self::PUBLIC_ROUTES, $names));

        $this->assertSame([], $stale, implode("\n", array_merge(
            ['أسماء في PUBLIC_ROUTES بلا مسار يقابلها:'],
            $stale
        )));
    }

    /**
     * كل مسار محمول على حدٍّ للمعدّل.
     *
     * الحدود مُعرَّفة في ApiServiceProvider، والاسم غير المعرَّف يرمي عند
     * أول طلب — فالخطر ليس حدّاً خاطئاً بل مساراً بلا حدّ إطلاقاً.
     */
    #[Test]
    public function every_api_route_is_rate_limited(): void
    {
        $unlimited = [];

        foreach ($this->apiRoutes() as $route) {
            if ($route->getName() === 'api.v1.health') {
                continue;
            }

            $throttled = array_filter(
                $route->gatherMiddleware(),
                static fn ($middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:')
            );

            if ($throttled === []) {
                $unlimited[] = $route->uri().' ('.$route->getName().')';
            }
        }

        $this->assertSame([], $unlimited, implode("\n", array_merge(
            ['مسارات API بلا حدّ معدّل:'],
            $unlimited
        )));
    }

    /**
     * @return list<Route>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'api/')
        ));
    }
}
