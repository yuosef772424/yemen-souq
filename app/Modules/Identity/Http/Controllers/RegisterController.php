<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Exceptions\PhoneVerificationUnavailableException;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Models\UserProfile;
use App\Modules\Identity\Services\PhoneVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class RegisterController
{
    public function __construct(
        private readonly PhoneVerificationService $verification,
    ) {
    }

    public function create(): View
    {
        return view('identity::register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $data = $request->validated();

        /**
         * الحساب والملف الشخصي في معاملة واحدة.
         *
         * لو فشل إنشاء الملف الشخصي بعد نجاح الحساب، لبقي مستخدم بلا
         * صف عرض — فتنكسر كل صفحة تقرأ displayName بلا خطأ واضح.
         * وهذا من نفس عائلة price_in_base الفارغ: عطل صامت.
         */
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            UserProfile::create([
                'user_id' => $user->id,
                'display_name' => $data['name'],
            ]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        try {
            $code = $this->verification->issue($user->phone, $this->ipPrefix($request->ip()));
        } catch (PhoneVerificationUnavailableException $e) {
            // القناة غير جاهزة على مستوى الخادم — لا شأن للمستخدم بذلك،
            // ورسالة الاستثناء تسمّي أصنافاً ومتغيّرات بيئة لا يجوز أن
            // يراها. السبب الحقيقي يبقى في السجلّ، والحساب أُنشئ فعلاً.
            report($e);

            return redirect()
                ->route('phone.verify')
                ->with('status', 'أنشئ حسابك. توثيق الهاتف متوقّف مؤقتاً — سيعمل النشر بمجرد تفعيله.');
        } catch (RuntimeException $e) {
            return redirect()->route('phone.verify')->with('status', $e->getMessage());
        }

        return redirect()
            ->route('phone.verify')
            ->with('status', 'أنشئ حسابك. وثّق رقمك لتتمكن من النشر.')
            ->with('demo_code', $code);
    }

    /**
     * مقطع من عنوان IP — لمنع الإساءة فقط، ولا يُخزَّن كاملاً.
     * الـREADME يعِد بأن لا عنوان IP يُخزَّن؛ هذا العمود مقطوع بقصد
     * والوعد يخصّ سجل السلوك، لا حدّ إساءة على جدول رموز.
     */
    private function ipPrefix(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.0.0" : null;
    }
}