<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Enums\VerificationResult;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Services\PhoneVerificationService;
use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * استعادة كلمة المرور برمز الجوال.
 *
 * ═══ لماذا بالجوال لا بالبريد ═══
 *
 * `password_reset_tokens` جدول لارافل قائم ولم يُستعمل، ومسار البريد هو
 * الافتراضي في كل مشروع. لكنّ البريد في هذا السياق حقلٌ يملؤه المستخدم
 * مرة ولا يفتحه: الرقم هو ما يحفظه الناس وهو ما دخلوا به — وقد بُني عليه
 * التوثيق أصلاً. مسارُ استعادةٍ عبر بريدٍ لا يُقرأ ليس استعادة.
 *
 * والبنية جاهزة كاملةً: `PhoneVerificationService` يحمل التجزئة والصلاحية
 * وسقف المحاولات وحدّ الإرسال. إعادة كتابة أيٍّ منها لأجل الاستعادة تعني
 * سياستين أمنيتين تتفرّقان عند أول تعديل.
 *
 * ═══ لا كشف عن وجود الحساب ═══
 *
 * أخطر ما في أي مسار استعادة: أن يقول «هذا الرقم غير مسجَّل». من يجرّب
 * ألف رقم يحصل على قائمة مستخدمي المنصة — وهي في سوق إعلانات قائمةُ من
 * يملك ما يُباع. فالاستجابة واحدة في الحالتين: نفس التوجيه ونفس الرسالة
 * ونفس الخطوة التالية، ولا رمزَ يُصدَر لرقم غير مسجَّل.
 *
 * ═══ الرقم في الجلسة لا في حقل مخفي ═══
 *
 * الرمز مربوط بالرقم في قاعدة البيانات، فحقلٌ مخفي لن يسمح بتغيير الضحية.
 * لكنه يضع رقم جوال في صفحة تُترك مفتوحة على شاشة. والجلسة تكفي: المسار
 * كله خطوتان متتاليتان في نفس المتصفح.
 */
class PasswordResetController
{
    private const SESSION_PHONE = 'password_reset_phone';

    /** طلبات في الساعة لكل رقم */
    private const RATE_LIMIT = 5;

    public function __construct(
        private readonly PhoneVerificationService $verification,
    ) {}

    // ─── الخطوة الأولى: اطلب الرمز ───

    public function request(): View
    {
        return view('identity::forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate(
            ['phone' => ['required', 'string']],
            [],
            ['phone' => 'رقم الجوال']
        );

        $phone = YemeniPhone::normalize($request->input('phone'));

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'أدخل رقم جوال يمني صحيح — مثل 0771234567.',
            ]);
        }

        $this->guardRate($phone);

        $user = User::query()->where('phone', $phone)->first();

        // الرمز يُصدَر للمسجَّل وحده، والاستجابة واحدة للجميع.
        //
        // ملاحظة للتطوير: مع سائق العرض لا يظهر رمز لرقم غير مسجَّل،
        // وهو فارقٌ مرئي في التطوير وحده — في الإنتاج يوصّل المزوّد
        // بلا أثر على الشاشة، فتتطابق الحالتان تماماً.
        $code = null;

        if ($user !== null) {
            try {
                $code = $this->verification->issue($phone, $request->ip());
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['phone' => $e->getMessage()]);
            }
        }

        return redirect()
            ->route('password.reset')
            ->with(self::SESSION_PHONE, $phone)
            ->with('demo_code', $code)
            ->with('status', 'إن كان الرقم مسجَّلاً فقد أُرسل إليه رمز من ستة أرقام.');
    }

    // ─── الخطوة الثانية: الرمز وكلمة المرور الجديدة ───

    public function edit(Request $request): View|RedirectResponse
    {
        $phone = $this->phoneFromSession($request);

        if ($phone === null) {
            return redirect()
                ->route('password.request')
                ->with('error', 'انتهت الجلسة. أدخل رقمك من جديد.');
        }

        // الرقم يُعاد إلى الجلسة: الخطوة الثانية قد تُعاد عدة مرات
        // (رمز خاطئ، كلمة مرور مرفوضة)، وflash يعيش طلباً واحداً —
        // فبلا الإبقاء يسقط المستخدم إلى البداية بعد أول خطأ مطبعي
        $request->session()->keep(self::SESSION_PHONE);

        return view('identity::reset-password', [
            'phone' => $phone,
            'secondsRemaining' => $this->verification->secondsRemaining($phone),
        ]);
    }

    /**
     * التغيير.
     *
     * ما يفعله بعد الحفظ يستحق الانتباه:
     *
     *  · **يبطل «أبقني مسجَّلاً» على كل الأجهزة.** من استعاد كلمته الغالب
     *    أنه فقد جهازه أو خشي اختراقاً، وكلمةٌ جديدة مع كوكي قديم يعمل
     *    ليست استعادة. تدوير remember_token يقطع كل جلسة محفوظة.
     *
     *  · **يعتبر الرقم موثَّقاً.** الدليل هو الدليل نفسه: استلام رمز على
     *    الرقم. وطلب إثباته مرة ثانية بعد ثوانٍ احتكاكٌ بلا مقابل أمني.
     *
     *  · **لا يُسجّل الدخول.** فحص الحساب الموقوف والمحظور يعيش في
     *    LoginController وحده، وتكراره هنا يعني موضعين يتفرّقان. والمرور
     *    من الدخول يجعل القاعدة واحدة: لا أحد يدخل بلا ذلك الفحص.
     */
    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $phone = $this->phoneFromSession($request);

        if ($phone === null) {
            return redirect()
                ->route('password.request')
                ->with('error', 'انتهت الجلسة. أدخل رقمك من جديد.');
        }

        $result = $this->verification->verify($phone, $request->string('code')->value());

        if ($result !== VerificationResult::Verified) {
            $request->session()->keep(self::SESSION_PHONE);

            return back()->withErrors(['code' => $result->message()]);
        }

        $user = User::query()->where('phone', $phone)->first();

        // الرمز صحيح والحساب اختفى بينهما — حذف في نفس الدقيقة. لا رسالة
        // خاصة به: أي تمييز هنا يعيد فتح باب كشف الحسابات من الخلف
        if ($user === null) {
            return redirect()->route('login')->with('status', 'يمكنك الدخول الآن.');
        }

        $user->forceFill([
            'password' => $request->string('password')->value(),
            'remember_token' => Str::random(60),
            'phone_verified_at' => $user->phone_verified_at ?? now(),
        ])->save();

        $request->session()->forget(self::SESSION_PHONE);
        RateLimiter::clear($this->rateKey($phone));

        return redirect()
            ->route('login')
            ->with('status', 'غُيّرت كلمة المرور. سجّل دخولك بها الآن.');
    }

    // ─── الداخل ───

    private function phoneFromSession(Request $request): ?string
    {
        $phone = $request->session()->get(self::SESSION_PHONE);

        return is_string($phone) && YemeniPhone::isValid($phone) ? $phone : null;
    }

    /**
     * حدّ على الطلب فوق حدّ الإرسال في الخدمة.
     *
     * الخدمة تحدّ الرموز الصادرة لرقم مسجَّل. وهذا الحدّ يشمل **الأرقام
     * غير المسجَّلة** أيضاً — وهي بالضبط ما يجرّبه من يعدّ قائمة
     * المستخدمين، لأن مسارها لا يصدر رمزاً فلا يمسّ حدّ الخدمة إطلاقاً.
     */
    private function guardRate(string $phone): void
    {
        $key = $this->rateKey($phone);

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            throw ValidationException::withMessages([
                'phone' => 'طلبات كثيرة. انتظر قليلاً ثم أعد المحاولة.',
            ]);
        }

        RateLimiter::hit($key, 3600);
    }

    private function rateKey(string $phone): string
    {
        return 'password-reset:'.$phone;
    }
}
