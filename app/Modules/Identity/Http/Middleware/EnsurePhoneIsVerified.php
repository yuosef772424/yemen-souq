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

        /**
         * الرد يتبع ما يفهمه الطالب.
         *
         * التحويل إلى صفحة التوثيق صحيحٌ في المتصفح وبلا معنى في تطبيق:
         * عميل API يتبع التحويل فيستقبل **صفحة HTML** بحالة 200، فيقرأها
         * كنجاح — أي أن البوابة تُقرأ كأن النشر تمّ. و403 برمز صريح يجعل
         * التطبيق يفتح شاشة التوثيق بنفسه.
         *
         * وcode لا الرسالة وحدها: نصّ الرسالة يتغيّر، والتطبيق لا يجوز أن
         * يفرّق بين حالتين بمطابقة نصّ عربي.
         */
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'وثّق رقم هاتفك قبل نشر إعلان.',
                'code' => 'phone_not_verified',
            ], 403);
        }

        return redirect()
            ->route('phone.verify')
            ->with('status', 'وثّق رقم هاتفك قبل نشر إعلان.');
    }
}