<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController
{
    public function create(): View
    {
        return view('identity::login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [], ['identifier' => 'الهاتف أو البريد', 'password' => 'كلمة المرور']);

        $this->guardRate($request);

        /**
         * الدخول بالهاتف أو بالبريد من نفس الحقل.
         *
         * في اليمن الرقم هو ما يحفظه الناس، والبريد ثانوي. إجبار
         * المستخدم على تذكّر أيّهما سجّل به احتكاك بلا مقابل.
         */
        $phone = YemeniPhone::normalize($request->input('identifier'));

        $credentials = $phone !== null
            ? ['phone' => $phone, 'password' => $request->input('password')]
            : ['email' => $request->input('identifier'), 'password' => $request->input('password')];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($this->rateKey($request), 60);

            throw ValidationException::withMessages([
                'identifier' => 'البيانات غير صحيحة.',
            ]);
        }

        /**
         * الحساب الموقوف يُرفَض برسالة صريحة.
         *
         * "البيانات غير صحيحة" هنا كذب يدفع المستخدم إلى إعادة تعيين
         * كلمة مروره مرة بعد مرة بلا فائدة — والعمود status موجود
         * أصلاً في الجدول بلا منطق يقرأه.
         */
        if (! Auth::user()->isActive()) {
            $status = Auth::user()->status;
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'identifier' => $status === 'banned'
                    ? 'هذا الحساب محظور.'
                    : 'هذا الحساب موقوف مؤقتاً.',
            ]);
        }

        RateLimiter::clear($this->rateKey($request));

        $request->session()->regenerate();
        Auth::user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('search'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('search');
    }

    private function guardRate(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->rateKey($request), 5)) {
            throw ValidationException::withMessages([
                'identifier' => 'محاولات كثيرة. انتظر دقيقة ثم أعد المحاولة.',
            ]);
        }
    }

    private function rateKey(Request $request): string
    {
        return 'login:' . mb_strtolower((string) $request->input('identifier'));
    }
}