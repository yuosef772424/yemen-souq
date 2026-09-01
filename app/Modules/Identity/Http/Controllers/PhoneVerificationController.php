<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Enums\VerificationResult;
use App\Modules\Identity\Exceptions\PhoneVerificationUnavailableException;
use App\Modules\Identity\Services\PhoneVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

class PhoneVerificationController
{
    public function __construct(
        private readonly PhoneVerificationService $verification,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedPhone()) {
            return redirect()->route('search')->with('status', 'رقمك موثَّق بالفعل.');
        }

        return view('identity::verify-phone', [
            'phone' => $user->phone,
            'secondsRemaining' => $this->verification->secondsRemaining($user->phone),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(
            ['code' => ['required', 'string']],
            [],
            ['code' => 'الرمز']
        );

        $user = $request->user();
        $result = $this->verification->verify($user->phone, $request->string('code')->value());

        if ($result !== VerificationResult::Verified) {
            return back()->withErrors(['code' => $result->message()]);
        }

        $user->markPhoneAsVerified();

        // intended يعيده إلى صفحة النشر إن كان قادماً منها
        return redirect()
            ->intended(route('listing.create'))
            ->with('status', $result->message());
    }

    public function resend(Request $request): RedirectResponse
    {
        try {
            $code = $this->verification->issue($request->user()->phone);
        } catch (PhoneVerificationUnavailableException $e) {
            report($e);

            return back()->withErrors(['code' => 'توثيق الهاتف متوقّف مؤقتاً. حاول لاحقاً.']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()
            ->with('status', 'أُرسل رمز جديد.')
            ->with('demo_code', $code);
    }
}