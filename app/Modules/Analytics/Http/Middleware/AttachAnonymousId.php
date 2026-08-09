<?php

namespace App\Modules\Analytics\Http\Middleware;

use App\Modules\Analytics\Services\EventRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * معرّف مجهول ثابت في كوكي.
 *
 * ليس معرّف هوية: لا يحمل أي بيانات، ولا يُربط بشخص إلا إن سجّل الزائر
 * حساباً بنفسه. الغرض الوحيد هو معرفة أن هذه الزيارات من نفس المتصفح،
 * حتى تُحسب رحلة البحث بشكل صحيح.
 */
class AttachAnonymousId
{
    private const LIFETIME_MINUTES = 60 * 24 * 365;

    public function handle(Request $request, Closure $next): Response
    {
        $existing = $request->cookie(EventRecorder::ANON_COOKIE);

        $anonId = is_string($existing) && Str::isUuid($existing)
            ? $existing
            : (string) Str::uuid();

        $request->attributes->set('anon_id', $anonId);

        $response = $next($request);

        if ($existing !== $anonId) {
            Cookie::queue(Cookie::make(
                name: EventRecorder::ANON_COOKIE,
                value: $anonId,
                minutes: self::LIFETIME_MINUTES,
                httpOnly: true,
                sameSite: 'lax',
            ));
        }

        return $response;
    }
}
