<?php

use App\Modules\Shared\Http\Problem\ApiProblemException;
use App\Modules\Shared\Http\Problem\ProblemDetails;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * الثقة بوسيط المنصة — وبلاها تُبنى كل الروابط بـhttp.
         *
         * ═══ العطل الذي كشفها ═══
         *
         * المنصة تنهي TLS عند حافتها وتمرّر الطلب داخلياً بـhttp عادي.
         * فيرى لارافل مخططاً غير آمن ويبني به كل رابط — بينما المتصفح
         * فتح الصفحة على https. والنتيجة أن كل أصل (CSS، JS) يُطلب
         * بـhttp داخل صفحة https، فيحجبه المتصفح كمحتوى مختلط.
         *
         * وأثره في أول نشر: الموقع يعمل ويعرض محتواه كاملاً، **بلا أي
         * تنسيق**. لا خطأ في سجلّ الخادم ولا في صفحة الشبكة — الملف
         * يُخدَم سليماً 200، والمتصفح وحده يرفض تطبيقه.
         *
         * والأثر أوسع من التنسيق: `url()` و`route()` و`asset()` وكل
         * إعادة توجيه تخرج بـhttp — ومنها روابط التحقق والاستعادة.
         *
         * ═══ ولماذا `*` لا قائمة عناوين ═══
         *
         * وسطاء PaaS (Railway، Render، Fly) عناوينهم داخلية ومتغيّرة،
         * ولا تُنشَر قائمة ثابتة يمكن تثبيتها. والحاوية لا تُستقبل طلباً
         * إلا عبر ذلك الوسيط — فلا طريق يلتفّ حوله ليزوّر الترويسات.
         *
         * المقايضة معلومة: من يصل الحاوية مباشرةً يستطيع انتحال عنوان
         * العميل عبر X-Forwarded-For. وهو مقبول هنا لأن الشبكة الخاصة
         * للمشروع وحدها تصلها، ولأن البديل — قائمة عناوين تتغيّر بلا
         * إشعار — يعني موقعاً يسقط بلا سبب ظاهر عند أول تغيير عندهم.
         */
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * أخطاء ‎/api/*‎ بصيغة RFC 9457 — انظر ProblemDetails.
         *
         * ثلاثة أسطر لا أكثر، والمنطق كله في Shared/Http/Problem: هذا
         * الملف يُقرأ مرة ولا يُختبر، وما يعيش في صنفٍ يُختبر.
         *
         * والفحص داخل `ProblemDetails::render` لا هنا: callback يعيد null
         * لغير ‎/api‎ فيتابع لارافل مساره المعتاد (صفحات الويب وإعادة
         * التوجيه) بلا تغيير.
         */
        $exceptions->render(
            fn (Throwable $e, Request $request) => ProblemDetails::render($e, $request)
        );

        // ما عدا الـcallback: كل ما يخصّ ‎/api‎ يُعرض JSON حتى لو غابت
        // ترويسة Accept، فلا يتسرّب إليه HTML من مسارٍ لا يمرّ بوسيط
        // ForceJsonResponse (404 على مسار غير موجود).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        /**
         * خدمة تُلقي ApiProblemException قد تُستدعى من الويب أيضاً.
         *
         * التحويل هنا وقت العرض لا بـ`map()`: انظر ProblemDetails::renderForWeb.
         * مسجَّل بعد callback الأعلى فيسبقه الـAPI، ويعيد null لمسار ‎/api‎.
         */
        $exceptions->render(
            fn (ApiProblemException $e, Request $request) => ProblemDetails::renderForWeb($e, $request)
        );

        // خطأ العميل المقصود (4xx) ليس عطلاً يستحق سطراً في السجلّ
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => $e instanceof ApiProblemException && $e->status < 500
        );
    })->create();
