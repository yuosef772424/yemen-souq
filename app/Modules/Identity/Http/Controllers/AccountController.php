<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Http\Requests\UpdatePasswordRequest;
use App\Modules\Identity\Http\Requests\UpdatePhoneRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Models\UserProfile;
use App\Modules\Identity\Services\AvatarService;
use App\Modules\Identity\Services\PhoneVerificationService;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * إعدادات الحساب — الشاشة التي كانت ناقصة.
 *
 * كان المستخدم يسجّل اسمه مرة واحدة عند التسجيل ولا يملك بعدها طريقاً
 * إلى تغييره، ولا إلى تغيير كلمة مروره من داخل حسابه، ولا إلى تصحيح
 * رقمٍ كتبه خطأً، ولا إلى إغلاق حسابه. أربعة نواقص من عائلة واحدة:
 * المنصة تعرف كيف تُنشئ حساباً ولا تعرف كيف يعيش صاحبه فيه.
 *
 * ═══ صفحة واحدة بأربعة نماذج ═══
 *
 * لا صفحة لكل حقل. النماذج مستقلّة في الإرسال — كلٌّ إلى مساره — لكنها
 * مجتمعة في العرض، لأن السؤال الذي يأتي به المستخدم هو «أين أعدّل
 * حسابي» لا «أين أعدّل اسمي المعروض».
 *
 * والخطأ في نموذجٍ لا يمسّ غيره: كل نموذج يحمل حزمة أخطاء باسمه
 * (`$errors->profile`, `$errors->password`, …) — وبلا ذلك يظهر «كلمة
 * المرور الحالية غير صحيحة» فوق نموذج الاسم.
 *
 * ═══ وما لا تفعله هذه الشاشة ═══
 *
 * لا تلمس `status` ولا `role` ولا `phone_verified_at` مباشرةً. الأولان
 * قرارا إشراف، والثالث أثرُ فعلٍ لا حقلٌ يُملأ.
 */
class AccountController
{
    public function __construct(
        private readonly PhoneVerificationService $verification,
        private readonly AvatarService $avatars,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user()->load('profile');

        return view('identity::settings', [
            'user' => $user,
            'profile' => $user->profile,
            'governorates' => Location::active()->governorates()->orderBy('sort_order')->get(),
            'currencies' => Currency::active()->get(),
        ]);
    }

    /**
     * الاسم والعرض معاً.
     *
     * `users.name` و`user_profiles.display_name` حقلان لا واحد: الأول
     * اسم الحساب والثاني ما يراه السوق. والصفّ يُنشأ إن غاب — حسابات
     * البذور والـfactory لا تملك واحداً، وتعديلٌ يفترض وجوده كان سيسقط
     * عليها وحدها.
     */
    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($user, $data): void {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            UserProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'display_name' => $data['display_name'],
                    'bio' => $data['bio'] ?? null,
                    'location_id' => $data['location_id'] ?? null,
                    'preferred_currency' => $data['preferred_currency'] ?? null,
                    'is_business' => $data['is_business'] ?? false,
                    'business_name' => ($data['is_business'] ?? false) ? ($data['business_name'] ?? null) : null,
                ]
            );
        });

        $this->applyAvatar($request);

        return back()->with('status', 'حُفظت بيانات حسابك.');
    }

    /**
     * الصورة بعد المعاملة لا داخلها.
     *
     * الكتابة على القرص لا تتراجع مع `rollback`: لو فشل حفظ البيانات بعد
     * رفع الملف لبقي ملفٌ يتيم بلا صفٍّ يشير إليه. وبالترتيب الحالي أسوأ
     * ما يقع أن تُحفَظ البيانات ولا تُحفَظ الصورة — والمستخدم يرى ذلك
     * ويعيد الرفع.
     *
     * والرفع يسبق الحذف في الفحص: من أرسل صورةً جديدة **وأشّر** الحذف
     * يقصد الاستبدال بالأرجح لا الإفراغ.
     */
    private function applyAvatar(UpdateProfileRequest $request): void
    {
        // fresh(): الصفّ قد يكون أُنشئ للتوّ في المعاملة أعلاه، والنسخة
        // المحمَّلة في الطلب لا تعرفه
        $user = $request->user()->fresh();

        if ($request->hasFile('avatar')) {
            $this->avatars->replace($user, $request->file('avatar'));

            return;
        }

        if ($request->boolean('remove_avatar')) {
            $this->avatars->remove($user);
        }
    }

    /**
     * كلمة المرور — ومعها تدوير رمز «تذكّرني».
     *
     * بلا التدوير تبقى كوكي جهازٍ قديم صالحةً بعد تغيير الكلمة، فيدخل
     * منها من دُفع إلى التغيير بسببه. وهو نفس ما تفعله الاستعادة.
     */
    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        $request->session()->regenerate();

        return back()->with('status', 'غُيّرت كلمة المرور.');
    }

    /**
     * الرقم الجديد يبدأ غير موثَّق — دائماً.
     *
     * القبول بلا إعادة توثيق يجعل تغيير الرقم بابَ التفافٍ على البوابة
     * كلها: يوثّق صاحب الحساب رقماً يملكه، ثم يبدّله بأيّ رقم فينشر
     * باسمه. ولذلك `phone_verified_at` يُصفَّر في نفس الحفظة.
     *
     * وفشل الإرسال لا يُلغي التغيير: الرقم تغيّر فعلاً، والرمز يُطلب
     * ثانيةً من صفحة التوثيق. والعكس يعني حساباً معلَّقاً بين رقمين
     * لأن مزوّد الرسائل تعثّر لحظتها.
     */
    public function updatePhone(UpdatePhoneRequest $request): RedirectResponse
    {
        $user = $request->user();
        $phone = $request->validated('phone');

        if ($phone === $user->phone && $user->hasVerifiedPhone()) {
            return back()->with('status', 'هذا هو رقمك الموثَّق بالفعل.');
        }

        $user->forceFill([
            'phone' => $phone,
            'phone_verified_at' => null,
        ])->save();

        try {
            $code = $this->verification->issue($phone);
        } catch (RuntimeException $e) {
            return redirect()->route('phone.verify')->with('status', $e->getMessage());
        }

        return redirect()
            ->route('phone.verify')
            ->with('status', 'غُيّر رقمك. وثّقه لتتمكن من النشر.')
            ->with('demo_code', $code);
    }

    /**
     * إغلاق الحساب.
     *
     * ═══ حذف منطقي لا حقيقي ═══
     *
     * الصفّ يبقى لأن غيره يشير إليه: محادثات ورسائل وبلاغات وسجلّ
     * إشراف. وحذفه فعلياً إمّا يجرّ معه ما لا يخصّ صاحبه وحده (نصف
     * محادثة)، أو يترك مفاتيح معلَّقة.
     *
     * ═══ والمعرّفان يُحرَّران ═══
     *
     * القيد الفريد على الهاتف والبريد لا يفرّق بين حسابٍ حيّ وحسابٍ
     * محذوف. فبلا التحرير لا يستطيع صاحبه العودة برقمه أبداً — وهو
     * رقمه هو، لا رقم المنصة. والبريد يُوسَم بدل أن يُفرَّغ لأن العمود
     * مطلوب.
     *
     * ═══ وما يُغلق معه ═══
     *
     * رموز الوصول (وإلا بقي التطبيق يعمل بحسابٍ أُغلق)، والإعلانات —
     * وهذه يتكفّل بها `UserObserver` في وحدة Catalog: الاتجاه لا يسمح
     * لوحدة الهوية أن تعرف الإعلانات.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag(
            'deleteAccount',
            ['current_password' => ['required', 'current_password']],
            [
                'current_password.required' => 'أدخل كلمة المرور لتأكيد الإغلاق.',
                'current_password.current_password' => 'كلمة المرور غير صحيحة.',
            ],
            ['current_password' => 'كلمة المرور'],
        );

        $user = $request->user();

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();

            $user->forceFill([
                'phone' => null,
                'email' => 'deleted-'.$user->id.'@'.User::DELETED_EMAIL_DOMAIN,
                'status' => 'suspended',
            ])->save();

            $user->delete();
        });

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('search')->with('status', 'أُغلق حسابك. نأسف لذهابك.');
    }
}
