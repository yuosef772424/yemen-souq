<?php

namespace App\Modules\Sms\Http\Controllers;

use App\Modules\Sms\Services\ChallengeVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * صفحة «أرسل الرمز إلينا» — ومعادلها في الـAPI.
 *
 * ═══ لماذا صفحة في هذه الوحدة لا في الهوية ═══
 *
 * الوحدة تُحذف. وصفحةٌ في `Identity` تشرح قناةً قد لا توجد تبقى بعد
 * الحذف تشير إلى مسارٍ ذهب. والرابط إليها من صفحة التوثيق مشروطٌ
 * بـ`Route::has` — فيختفي وحده.
 *
 * ═══ ولا يُعرض الرمز إلا للمستخدم صاحبه ═══
 *
 * المسار خلف `auth`، والتحدّي يُصدَر لـ`$request->user()` لا لرقمٍ من
 * الطلب. وبلا ذلك يستطيع أيّ أحد إصدار تحدٍّ لرقم غيره ثم انتظار أن
 * يرسله صاحبه مصادفةً.
 */
class ChallengeController
{
    public function __construct(
        private readonly ChallengeVerifier $challenges,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedPhone()) {
            return redirect()->route('search')->with('status', 'رقمك موثَّق بالفعل.');
        }

        try {
            $challenge = $this->challenges->issue($user);
        } catch (RuntimeException $exception) {
            return redirect()->route('phone.verify')->with('error', $exception->getMessage());
        }

        return view('sms::challenge', [
            'display' => $this->challenges->display($challenge['token']),
            'number' => $this->challenges->inboundNumber(),
            'minutes' => $this->challenges->ttlMinutes(),
            // رابط يفتح تطبيق الرسائل بالرقم والنصّ جاهزين — يوفّر
            // النسخَ اليدوي، وهو أكثر ما يُخطئ فيه من يكتب بالإبهام
            'smsLink' => $this->smsLink($challenge['token']),
        ]);
    }

    /**
     * نفس التحدّي للتطبيق.
     *
     * التطبيق يعرض الرمز ويفتح مؤلّف الرسائل بنفسه — فلا يحتاج صفحة.
     * والاستجابة تحمل ما يبنيه: الرمز المعروض، ورقمنا، والمهلة.
     */
    public function issue(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedPhone()) {
            return response()->json([
                'message' => 'رقمك موثَّق بالفعل.',
                'verified' => true,
            ]);
        }

        try {
            $challenge = $this->challenges->issue($user);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'challenge_rate_limited',
            ], 422);
        }

        return response()->json([
            'text' => $this->challenges->display($challenge['token']),
            'send_to' => $this->challenges->inboundNumber(),
            'expires_at' => $challenge['expires_at']->toIso8601String(),
            'sms_uri' => $this->smsLink($challenge['token']),
        ]);
    }

    private function smsLink(string $token): ?string
    {
        $number = config('sms.inbound.number');

        if (! is_string($number) || $number === '') {
            return null;
        }

        // sms:<number>?body=<text> — يفهمه أندرويد وiOS معاً
        return 'sms:'.$number.'?body='.rawurlencode($this->challenges->display($token));
    }
}
