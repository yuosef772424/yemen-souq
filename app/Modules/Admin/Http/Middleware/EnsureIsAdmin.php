<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * الطبقة الثانية: إدارة الحسابات للمدير وحده.
 *
 * وسيط منفصل لا شرط داخل EnsureIsStaff، لأن الفرق ليس درجةً في نفس
 * الفحص بل بوابتان مختلفتان: الأولى «هل تدخل اللوحة»، والثانية «هل
 * تملك حسابات الآخرين». دمجهما بمعامل نصّي (staff:admin) يجعل الخطأ
 * المطبعي في اسم المعامل ثغرة صامتة.
 */
class EnsureIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! UserRole::of($request->user())->canManageUsers()) {
            abort(403, 'هذا القسم للمدير وحده.');
        }

        return $next($request);
    }
}
