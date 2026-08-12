<?php

namespace App\Modules\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * كل ما يخرج من ‎/api‎ يخرج JSON — حتى الأخطاء.
 *
 * ═══ لماذا هذا ضروري وليس تجميلاً ═══
 *
 * لارافل يقرّر شكل استجابة الخطأ من ترويسة `Accept` عبر
 * `Request::expectsJson()`. فعميلٌ نسي الترويسة يحصل على **صفحة HTML**
 * لخطأ 404 أو 422 أو 419 — بينما المسار الناجح يعطيه JSON. والنتيجة
 * أسوأ من خطأ: التطبيق يحلّل النجاح ويختنق عند الفشل، فيظهر العطل
 * كـ«تعذّر الاتصال» لا كـ«الحقل مطلوب».
 *
 * والترويسة تُكتب على *الطلب* لا على الاستجابة بقصد: القرار يُتَّخذ في
 * معالج الاستثناءات قبل أن تصل الاستجابة إلى أي وسيط، فتعديلها بعد
 * الحدث لا يغيّر ما بُني أصلاً.
 *
 * ═══ ولماذا هنا لا في bootstrap/app.php ═══
 *
 * عقد الوحدات: مجلد يعمل بمجرد وجوده. وسيطٌ يخصّ مسارات هذه الوحدة
 * يُسجَّل في ملف مساراتها — وحذف المجلد يأخذه معه.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
