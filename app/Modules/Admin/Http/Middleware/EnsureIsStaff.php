<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بوابة لوحة التحكم.
 *
 * لماذا وسيط لا فحص في كل متحكّم: الفحص المنسي في متحكّم واحد يعني
 * لوحة تحكم مفتوحة لأي مستخدم مسجَّل. الوسيط على مجموعة المسارات كلها
 * يجعل النسيان مستحيلاً — تُضاف طريقة جديدة فتكون محميّة قبل أن تُكتب.
 *
 * ولماذا الاستدعاء بالاسم الكامل للصنف في ملف المسارات لا باسم مختصر:
 * الاسم المختصر يحتاج سطراً في bootstrap/app.php، أي أن نسخ مجلد
 * الوحدة لا يكفي لتشغيلها. الوحدة تُنسخ فتعمل.
 *
 * و404 لا 403 لغير الموظفين مقصود: 403 يؤكد للمتطفّل أن المسار قائم
 * وأن ما يمنعه صلاحية فقط. الإخفاء أرخص من الشرح.
 */
class EnsureIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        // الحساب الموقوف لا يدير شيئاً وإن كان دوره مديراً
        if (! UserRole::of($user)->isStaff() || $user->status !== 'active') {
            abort(404);
        }

        return $next($request);
    }
}
