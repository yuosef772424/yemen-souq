<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * بوابة النشر: رقم موثَّق.
 *
 * لماذا الهاتف لا البريد: إعلان برقم غير موثَّق إعلان لا يمكن التواصل
 * بشأنه — وهذا يضرب المشتري لا الناشر.
 *
 * ولماذا قابلة للتعطيل من config: الخطوتان الزائدتان تُثقلان من يجرّب
 * العرض التجريبي، والتعطيل بمفتاح بيئة أنظف من تعليق سطر في الكود.
 */
class EnsurePhoneIsVerified
{
    public function next(): void
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('identity.require_phone_verification_to_publish', true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && $user->hasVerifiedPhone()) {
            return $next($request);
        }

        return redirect()
            ->route('phone.verify')
            ->with('status', 'وثّق رقم هاتفك قبل نشر إعلان.');
    }
}