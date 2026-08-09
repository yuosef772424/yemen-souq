<?php

/**
 * scaffold-identity.php — وحدة الهوية كاملة.
 *
 * يُشغَّل من جذر المشروع:  php scaffold-identity.php
 *
 * التسجيل · الدخول (بالهاتف أو البريد) · توثيق الهاتف برمز مولَّد
 * مجزَّأ محدود المحاولات · بوابة نشر قابلة للتعطيل من config.
 *
 * لا موفّر خدمة جديد ولا تعديل يدوي في bootstrap: المُرسل يُحلّ من
 * config داخل الخدمة، والمسارات تُحمَّل بـrequire، والوسيط يُستدعى بصنفه.
 */

if (! is_dir(__DIR__ . '/app/Modules/Identity')) {
    fwrite(STDERR, "خطأ: شغّل هذا السكربت من جذر المشروع.\n");
    exit(1);
}

$files = [];

// ─────────────────────────────────────────────────────────────────────────────
// الهاتف اليمني
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Support/YemeniPhone.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Support;

/**
 * تطبيع أرقام الهواتف اليمنية إلى صيغة E.164 واحدة.
 *
 * لماذا هذا ضروري لا تجميلي: عمود phone فريد (unique). ومن يسجّل
 * بـ0771234567 ثم يسجّل بـ+967771234567 يُنشئ حسابين لنفس الرقم،
 * فينكسر التوثيق ويصبح الرقم غير صالح كهوية. التطبيع عند الحدّ
 * الخارجي هو ما يجعل القيد الفريد ذا معنى.
 *
 * المدخلات الواقعية: 0771234567 · 771234567 · +967771234567 ·
 * 00967771234567 · وبينها مسافات وشرطات وأرقام عربية.
 */
final class YemeniPhone
{
    /** بادئات الجوال العاملة في اليمن */
    private const MOBILE_PREFIXES = ['70', '71', '73', '77', '78'];

    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        // الأرقام العربية أولاً، ثم كل ما ليس رقماً
        $digits = strtr($input, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        // 00967… ثم 967… ثم 0… — بهذا الترتيب، وإلا أكل الشرط الأخير الأول
        if (str_starts_with($digits, '00967')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '967')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 9 || ! in_array(substr($digits, 0, 2), self::MOBILE_PREFIXES, true)) {
            return null;
        }

        return '+967' . $digits;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /** للعرض: +967771234567 → 0771234567 */
    public static function forDisplay(?string $e164): ?string
    {
        if ($e164 === null || ! str_starts_with($e164, '+967')) {
            return $e164;
        }

        return '0' . substr($e164, 4);
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// نموذج رمز التحقق
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Models/PhoneVerification.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PhoneVerification extends Model
{
    protected $table = 'phone_verifications';

    public $timestamps = false;

    protected $fillable = [
        'phone', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'ip_prefix', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** الرمز الوحيد القابل للاستخدام الآن لهذا الرقم */
    public function scopeUsableFor(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>=', now())
            ->latest('id');
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// عقد المُرسل ومنفّذاه
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Contracts/PhoneVerificationSender.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Contracts;

/**
 * قناة توصيل رمز التحقق.
 *
 * الغرض من العقد: تبديل مزوّد الرسائل لا يمس منطق التحقق إطلاقاً.
 * التجزئة والصلاحية وعدّ المحاولات كلها في الخدمة، والمُرسل يوصّل فقط.
 */
interface PhoneVerificationSender
{
    public function send(string $phone, string $code): void;

    /**
     * هل تُعرَض الرمز للمستخدم في الواجهة؟
     *
     * القرار في المُرسل لا في المتحكم: من يعرف أنه لا يوصّل فعلاً هو
     * الوحيد الذي يحق له أن يقول "اعرضه على الشاشة".
     */
    public function displaysCode(): bool;
}
PHP;

$files['app/Modules/Identity/Senders/DisplaySender.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Senders;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use RuntimeException;

/**
 * لا يُرسل شيئاً — يُعرَض الرمز على الشاشة.
 *
 * للتطوير والعرض التجريبي. الرمز مولَّد عشوائياً كما في الإنتاج تماماً،
 * فيمرّ بالتجزئة والصلاحية وعدّ المحاولات كلها. الفرق الوحيد قناة التوصيل.
 *
 * ولماذا لا رمز ثابت (123456): يتخطّى كل ما سبق، فيبقى المسار الحقيقي
 * غير مُجرَّب حتى يوم النشر — وإن نجا إلى الإنتاج فهو باب مفتوح.
 */
final class DisplaySender implements PhoneVerificationSender
{
    public function send(string $phone, string $code): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DisplaySender لا يعمل في الإنتاج. اضبط PHONE_VERIFICATION_DRIVER=sms.'
            );
        }

        // السجل يكفي كأثر؛ العرض يتم في الواجهة
        logger()->info('رمز تحقق (بيئة تطوير)', ['phone' => $phone]);
    }

    public function displaysCode(): bool
    {
        return true;
    }
}
PHP;

$files['app/Modules/Identity/Senders/SmsGatewaySender.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Senders;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use RuntimeException;

/**
 * مزوّد رسائل حقيقي — الموضع الذي ينتظر التكامل.
 *
 * موجود كعقد لا كوهم: وجوده يجعل قيمة config صالحة ويحدّد بدقة ما
 * يحتاج التنفيذ، ورميه استثناءً صريحاً أفضل من صنف يبدو عاملاً ويصمت.
 */
final class SmsGatewaySender implements PhoneVerificationSender
{
    public function send(string $phone, string $code): void
    {
        throw new RuntimeException(
            'لم يُربط مزوّد رسائل بعد. نفّذ هذا الصنف أو أعد PHONE_VERIFICATION_DRIVER=display.'
        );
    }

    public function displaysCode(): bool
    {
        return false;
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// نتيجة التحقق
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Enums/VerificationResult.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Enums;

/**
 * نتيجة محاولة تحقق.
 *
 * لماذا enum لا bool: "فشل" وحدها لا تكفي. الرمز المنتهي يحتاج زر
 * إعادة إرسال، والمقفل يحتاج انتظاراً، والخاطئ يحتاج محاولة أخرى —
 * ثلاث رسائل مختلفة للمستخدم من نفس القيمة false.
 */
enum VerificationResult: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Locked = 'locked';

    public function message(): string
    {
        return match ($this) {
            self::Verified => 'تم توثيق رقمك.',
            self::Invalid => 'الرمز غير صحيح.',
            self::Expired => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.',
            self::Locked => 'تجاوزت عدد المحاولات المسموح. اطلب رمزاً جديداً.',
        };
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// الخدمة
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Services/PhoneVerificationService.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\PhoneVerificationSender;
use App\Modules\Identity\Enums\VerificationResult;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Senders\DisplaySender;
use App\Modules\Identity\Senders\SmsGatewaySender;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * إصدار رموز التحقق والتحقق منها.
 *
 * كل السياسة هنا: طول الرمز، الصلاحية، سقف المحاولات، حدّ الإرسال.
 * المُرسل يوصّل فقط. هذا يعني أن تبديل المزوّد لا يغيّر سلوكاً أمنياً.
 *
 * الرمز لا يُخزَّن نصاً صريحاً أبداً — code_hash فقط. سبب مباشر: من
 * يقرأ الجدول (نسخة احتياطية مسروقة، منفذ SQL مكشوف) يستطيع توثيق
 * أي رقم. والتجزئة تجعل الجدول بلا قيمة للمهاجم.
 */
final class PhoneVerificationService
{
    public function __construct(
        private readonly ?PhoneVerificationSender $sender = null,
    ) {
    }

    /**
     * يُصدر رمزاً جديداً ويوصّله.
     *
     * @return string|null الرمز إن كانت القناة تعرضه، وإلا null
     * @throws RuntimeException عند تجاوز حدّ الإرسال
     */
    public function issue(string $phone, ?string $ipPrefix = null): ?string
    {
        $this->guardSendRate($phone);

        // رمز واحد صالح لكل رقم: الرموز السابقة تُستهلك.
        // بلا هذا يبقى رمز قديم صالحاً بعد طلب رمز جديد، فيتضاعف
        // سطح الهجوم بكل إعادة إرسال.
        PhoneVerification::where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        PhoneVerification::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($this->config('ttl_minutes')),
            'ip_prefix' => $ipPrefix,
            'created_at' => now(),
        ]);

        $sender = $this->sender();
        $sender->send($phone, $code);

        return $sender->displaysCode() ? $code : null;
    }

    public function verify(string $phone, string $code): VerificationResult
    {
        $record = PhoneVerification::query()->usableFor($phone)->first();

        if ($record === null) {
            return VerificationResult::Expired;
        }

        if ($record->attempts >= $this->config('max_attempts')) {
            return VerificationResult::Locked;
        }

        // العدّاد يزيد قبل الفحص: لو زاد بعده لكان بالإمكان تخطّيه
        // بإنهاء الطلب مبكراً، ولصار السقف بلا معنى.
        $record->increment('attempts');

        if (! Hash::check($code, $record->code_hash)) {
            return VerificationResult::Invalid;
        }

        $record->update(['consumed_at' => now()]);

        return VerificationResult::Verified;
    }

    /** كم ثانية حتى ينتهي الرمز الحالي — للعرض في الواجهة */
    public function secondsRemaining(string $phone): int
    {
        $record = PhoneVerification::query()->usableFor($phone)->first();

        return $record === null ? 0 : max(0, now()->diffInSeconds($record->expires_at, false));
    }

    // ---- الداخل ----

    private function guardSendRate(string $phone): void
    {
        $since = now()->subMinutes($this->config('send_window_minutes'));

        $sent = PhoneVerification::where('phone', $phone)
            ->where('created_at', '>=', $since)
            ->count();

        if ($sent >= $this->config('max_sends_per_window')) {
            throw new RuntimeException('طلبت رموزاً كثيرة. انتظر قليلاً ثم أعد المحاولة.');
        }
    }

    private function generateCode(): string
    {
        $length = (int) $this->config('code_length');
        $max = (10 ** $length) - 1;

        // random_int لا rand: مولّد آمن تشفيرياً. رمز يمكن تخمينه
        // من التوقيت لا يوثّق شيئاً.
        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function sender(): PhoneVerificationSender
    {
        if ($this->sender !== null) {
            return $this->sender;
        }

        return match (config('identity.phone_verification.driver')) {
            'sms' => app(SmsGatewaySender::class),
            default => app(DisplaySender::class),
        };
    }

    private function config(string $key): mixed
    {
        return config("identity.phone_verification.{$key}");
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// الوسيط
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Http/Middleware/EnsurePhoneIsVerified.php'] = <<<'PHP'
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
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// الطلبات
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Http/Requests/RegisterRequest.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\YemeniPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * التطبيع قبل التحقق لا بعده.
     *
     * لو تحقّقنا من المُدخل الخام ثم طبّعناه، لصار القيد الفريد على
     * الصيغة المكتوبة لا على الرقم — و0771234567 و+967771234567
     * يمرّان كحسابين مختلفين لنفس الشخص.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => YemeniPhone::normalize($this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'phone' => ['required', 'string', 'unique:users,phone'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'أدخل رقم جوال يمني صحيح — مثل 0771234567.',
            'phone.unique' => 'هذا الرقم مسجَّل بالفعل.',
            'email.unique' => 'هذا البريد مسجَّل بالفعل.',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'phone' => 'رقم الجوال',
            'email' => 'البريد الإلكتروني',
            'password' => 'كلمة المرور',
        ];
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// المتحكمات
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Http/Controllers/RegisterController.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
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
PHP;

$files['app/Modules/Identity/Http/Controllers/LoginController.php'] = <<<'PHP'
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
PHP;

$files['app/Modules/Identity/Http/Controllers/PhoneVerificationController.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Enums\VerificationResult;
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
        } catch (RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()
            ->with('status', 'أُرسل رمز جديد.')
            ->with('demo_code', $code);
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// المسارات
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Routes/web.php'] = <<<'PHP'
<?php

use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PhoneVerificationController;
use App\Modules\Identity\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

/*
| مسارات وحدة الهوية — تُحمَّل بـrequire من routes/web.php.
|
| لماذا لا موفّر خدمة: تسجيل موفّر يفرض تعديلاً يدوياً في
| bootstrap/providers.php، والوحدة بهذا الشكل مكتفية بذاتها.
| المقايضة: الربط في موفّر أكثر اصطلاحية.
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/verify-phone', [PhoneVerificationController::class, 'show'])->name('phone.verify');
    Route::post('/verify-phone', [PhoneVerificationController::class, 'verify']);
    Route::post('/verify-phone/resend', [PhoneVerificationController::class, 'resend'])->name('phone.resend');
});
PHP;

$files['routes/web.php'] = <<<'PHP'
<?php

use App\Modules\Catalog\Http\Controllers\ContactRevealController;
use App\Modules\Catalog\Http\Controllers\ListingController;
use App\Modules\Catalog\Http\Controllers\ListingCreateController;
use App\Modules\Identity\Http\Middleware\EnsurePhoneIsVerified;
use App\Modules\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| عام
|--------------------------------------------------------------------------
*/

Route::get('/', SearchController::class)->name('search');

Route::get('/listing/{listing}', [ListingController::class, 'show'])->name('listing.show');

Route::post('/listing/{listing}/contact', ContactRevealController::class)->name('listing.contact');

/*
|--------------------------------------------------------------------------
| النشر — يتطلب حساباً ورقماً موثَّقاً
|--------------------------------------------------------------------------
|
| الوسيط يُستدعى بصنفه لا باسم مستعار: الاسم المستعار يحتاج تسجيلاً في
| bootstrap/app.php، والصنف المباشر يعمل بلا أي تسجيل.
|
| بوابة الهاتف قابلة للتعطيل من config/identity.php — انظر
| REQUIRE_PHONE_VERIFICATION في .env.
|
| ولم يتغيّر ListingCreateController: DemoUser::current() تُرجع
| Auth::user() متى وُجد، وهو ما كتبه تعليقها منذ البداية.
|
*/

Route::middleware(['auth', EnsurePhoneIsVerified::class])->group(function (): void {
    Route::get('/add', [ListingCreateController::class, 'create'])->name('listing.create');
    Route::post('/add', [ListingCreateController::class, 'store'])->name('listing.store');
});

/*
|--------------------------------------------------------------------------
| الهوية
|--------------------------------------------------------------------------
*/

require base_path('app/Modules/Identity/Routes/web.php');
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// القوالب
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Resources/Views/register.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'حساب جديد')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">حساب جديد</h1>
    <p class="mt-1 text-sm text-muted">تحتاج حساباً لنشر إعلان. التصفّح والبحث بلا حساب.</p>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium">الاسم <span class="text-red-600">*</span></label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium">رقم الجوال <span class="text-red-600">*</span></label>
            <input id="phone" name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" required
                   placeholder="0771234567" dir="ltr"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm tabular-nums focus:border-brand focus:outline-none">
            <p class="mt-1 text-xs text-muted">سيُرسل إليه رمز تحقق. هو أيضاً رقم التواصل في إعلاناتك.</p>
        </div>

        <div>
            <label for="email" class="block text-sm font-medium">البريد الإلكتروني <span class="text-red-600">*</span></label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required dir="ltr"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">كلمة المرور <span class="text-red-600">*</span></label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
            <p class="mt-1 text-xs text-muted">ثمانية أحرف على الأقل.</p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">تأكيد كلمة المرور <span class="text-red-600">*</span></label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
            أنشئ الحساب
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        لديك حساب؟ <a href="{{ route('login') }}" class="font-medium text-brand-dark underline">دخول</a>
    </p>
</div>

@endsection
BLADE;

$files['app/Modules/Identity/Resources/Views/login.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'دخول')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">دخول</h1>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="identifier" class="block text-sm font-medium">رقم الجوال أو البريد</label>
            <input id="identifier" name="identifier" type="text" value="{{ old('identifier') }}" required autofocus
                   dir="ltr"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium">كلمة المرور</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" value="1" class="rounded border-line">
            <span>أبقني مسجَّلاً</span>
        </label>

        <button type="submit"
                class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
            دخول
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-muted">
        لا حساب لك؟ <a href="{{ route('register') }}" class="font-medium text-brand-dark underline">أنشئ حساباً</a>
    </p>
</div>

@endsection
BLADE;

$files['app/Modules/Identity/Resources/Views/verify-phone.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'توثيق رقم الجوال')

@section('content')

<div class="mx-auto max-w-md">
    <h1 class="text-xl font-bold">توثيق رقم الجوال</h1>
    <p class="mt-1 text-sm text-muted">
        أُرسل رمز من ستة أرقام إلى
        <span dir="ltr" class="font-medium tabular-nums text-ink">{{ \App\Modules\Identity\Support\YemeniPhone::forDisplay($phone) }}</span>
    </p>

    @if (session('demo_code'))
        <div role="status" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">بيئة تجريبية — لا رسائل تُرسَل فعلاً</p>
            <p class="mt-2">الرمز: <span dir="ltr" class="text-lg font-bold tabular-nums">{{ session('demo_code') }}</span></p>
            <p class="mt-2 text-xs">في الإنتاج يوصّل الرمز مزوّد رسائل ولا يظهر هنا إطلاقاً.</p>
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <ul class="space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('phone.verify') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="code" class="block text-sm font-medium">الرمز</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                   maxlength="6" required autofocus dir="ltr"
                   class="mt-1 w-full rounded-xl border border-line px-3 py-2.5 text-center text-lg tracking-[0.4em] tabular-nums focus:border-brand focus:outline-none">
            @if ($secondsRemaining > 0)
                <p class="mt-1 text-xs text-muted">تنتهي صلاحيته بعد {{ ceil($secondsRemaining / 60) }} دقيقة.</p>
            @endif
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
            تحقّق
        </button>
    </form>

    <form method="POST" action="{{ route('phone.resend') }}" class="mt-4">
        @csrf
        <button type="submit" class="w-full rounded-xl border border-line px-4 py-3 text-sm font-medium transition hover:bg-canvas">
            أرسل رمزاً جديداً
        </button>
    </form>
</div>

@endsection
BLADE;

// ─────────────────────────────────────────────────────────────────────────────
// التخطيط — روابط الحساب
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Shared/Resources/Views/layouts/app.blade.php'] = <<<'BLADE'
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'سوق يمني — إعلانات مبوبة')</title>
    <meta name="description" content="@yield('description', 'إعلانات مبوبة في اليمن — بحث دقيق يفرّق بين الريال اليمني والسعودي والدولار.')">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans-arabic:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">

<header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
        <a href="{{ route('search') }}" class="flex items-center gap-2 shrink-0">
            <span class="grid size-9 place-items-center rounded-lg bg-ink text-sm font-bold text-white">سوق</span>
            <span class="hidden text-base font-semibold sm:block">سوق يمني</span>
        </a>

        <div class="flex-1"></div>

        @auth
            <span class="hidden text-sm text-muted sm:block">{{ auth()->user()->displayName() }}</span>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink">
                    خروج
                </button>
            </form>
        @else
            <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink">
                دخول
            </a>
        @endauth

        <a href="{{ route('listing.create') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
            أضف إعلانك
        </a>
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-6">
    @if (session('status'))
        <div role="status" class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
            <p class="font-medium">{{ session('status') }}</p>
            @foreach (session('warnings', []) as $warning)
                <p class="mt-1 text-xs text-green-800">{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    @yield('content')
</main>

<footer class="mt-16 border-t border-line py-8">
    <div class="mx-auto max-w-6xl px-4 text-sm text-muted">
        <p>مشروع تجريبي مستقل لعرض مقاربة في البحث والفلترة داخل السياق اليمني. ليس نسخة من أي منصة قائمة.</p>
        <p class="mt-2">أسعار الصرف المستخدمة تجريبية وليست أسعاراً حقيقية.</p>
    </div>
</footer>

</body>
</html>
BLADE;

// ─────────────────────────────────────────────────────────────────────────────
// الاختبارات
// ─────────────────────────────────────────────────────────────────────────────

$files['tests/Unit/Identity/YemeniPhoneTest.php'] = <<<'PHP'
<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Support\YemeniPhone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class YemeniPhoneTest extends TestCase
{
    #[Test]
    #[DataProvider('validNumbers')]
    public function it_normalizes_every_written_form_to_one_value(string $input): void
    {
        $this->assertSame('+967771234567', YemeniPhone::normalize($input));
    }

    public static function validNumbers(): array
    {
        return [
            'محلي بصفر' => ['0771234567'],
            'بلا صفر' => ['771234567'],
            'دولي بعلامة' => ['+967771234567'],
            'دولي بصفرين' => ['00967771234567'],
            'بمسافات وشرطات' => ['0 77-123 4567'],
            'بأرقام عربية' => ['٠٧٧١٢٣٤٥٦٧'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNumbers')]
    public function it_rejects_what_is_not_a_yemeni_mobile(?string $input): void
    {
        $this->assertNull(YemeniPhone::normalize($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'فارغ' => [''],
            'null' => [null],
            'قصير' => ['07712345'],
            'طويل' => ['07712345678'],
            'أرضي' => ['012345678'],
            'بادئة غير عاملة' => ['0751234567'],
            'نص' => ['رقمي عندك'],
        ];
    }

    #[Test]
    public function it_formats_back_for_display(): void
    {
        $this->assertSame('0771234567', YemeniPhone::forDisplay('+967771234567'));
    }
}
PHP;

$files['tests/Feature/Identity/RegistrationTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_account_and_its_profile_together(): void
    {
        $response = $this->post('/register', $this->payload());

        $response->assertRedirect(route('phone.verify'));

        $user = User::where('email', 'ali@example.test')->first();

        $this->assertNotNull($user);
        $this->assertNotNull($user->profile, 'الحساب بلا ملف شخصي — عطل صامت');
        $this->assertSame('علي محمد', $user->profile->display_name);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function it_normalizes_the_phone_before_storing_it(): void
    {
        $this->post('/register', $this->payload(['phone' => '0771234567']));

        $this->assertDatabaseHas('users', ['phone' => '+967771234567']);
    }

    #[Test]
    public function the_same_number_written_differently_cannot_register_twice(): void
    {
        $this->post('/register', $this->payload(['phone' => '0771234567']));
        $this->post('/logout');

        $response = $this->post('/register', $this->payload([
            'phone' => '+967771234567',
            'email' => 'other@example.test',
        ]));

        $response->assertSessionHasErrors('phone');
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function it_rejects_a_number_that_is_not_a_yemeni_mobile(): void
    {
        $response = $this->post('/register', $this->payload(['phone' => '012345678']));

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    #[Test]
    public function it_normalizes_the_display_name_for_search(): void
    {
        $this->post('/register', $this->payload(['name' => 'أحمد الحُسَيني']));

        $user = User::first();

        $this->assertSame('احمد الحسيني', $user->profile->name_normalized);
    }

    #[Test]
    public function a_new_account_starts_with_an_unverified_phone(): void
    {
        $this->post('/register', $this->payload());

        $this->assertFalse(User::first()->hasVerifiedPhone());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'علي محمد',
            'phone' => '0771234567',
            'email' => 'ali@example.test',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ], $overrides);
    }
}
PHP;

$files['tests/Feature/Identity/LoginTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'علي محمد',
            'phone' => '+967771234567',
            'email' => 'ali@example.test',
            'password' => Hash::make('password1234'),
        ]);
    }

    #[Test]
    public function it_accepts_a_phone_written_in_the_local_form(): void
    {
        $this->post('/login', ['identifier' => '0771234567', 'password' => 'password1234'])
            ->assertRedirect(route('search'));

        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function it_accepts_an_email_in_the_same_field(): void
    {
        $this->post('/login', ['identifier' => 'ali@example.test', 'password' => 'password1234']);

        $this->assertAuthenticatedAs($this->user);
    }

    #[Test]
    public function it_records_the_login_time(): void
    {
        $this->post('/login', ['identifier' => '0771234567', 'password' => 'password1234']);

        $this->assertNotNull($this->user->fresh()->last_login_at);
    }

    #[Test]
    public function a_suspended_account_is_told_so_instead_of_being_called_wrong(): void
    {
        $this->user->forceFill(['status' => 'suspended'])->save();

        $response = $this->post('/login', ['identifier' => '0771234567', 'password' => 'password1234']);

        $response->assertSessionHasErrors('identifier');
        $this->assertStringContainsString('موقوف', session('errors')->first('identifier'));
        $this->assertGuest();
    }

    #[Test]
    public function a_banned_account_gets_its_own_message(): void
    {
        $this->user->forceFill(['status' => 'banned'])->save();

        $this->post('/login', ['identifier' => '0771234567', 'password' => 'password1234']);

        $this->assertStringContainsString('محظور', session('errors')->first('identifier'));
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $this->post('/login', ['identifier' => '0771234567', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    #[Test]
    public function logging_out_ends_the_session(): void
    {
        $this->actingAs($this->user)->post('/logout')->assertRedirect(route('search'));

        $this->assertGuest();
    }
}
PHP;

$files['tests/Feature/Identity/PhoneVerificationTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Identity\Enums\VerificationResult;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Services\PhoneVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private PhoneVerificationService $service;

    private User $user;

    private string $phone = '+967771234567';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PhoneVerificationService::class);

        $this->user = User::create([
            'name' => 'علي محمد',
            'phone' => $this->phone,
            'email' => 'ali@example.test',
            'password' => Hash::make('password1234'),
        ]);
    }

    #[Test]
    public function the_code_is_never_stored_in_plain_text(): void
    {
        $code = $this->service->issue($this->phone);

        $record = PhoneVerification::first();

        $this->assertNotSame($code, $record->code_hash);
        $this->assertTrue(Hash::check($code, $record->code_hash));
    }

    #[Test]
    public function the_display_driver_returns_the_code_so_the_ui_can_show_it(): void
    {
        $code = $this->service->issue($this->phone);

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
    }

    #[Test]
    public function the_correct_code_verifies(): void
    {
        $code = $this->service->issue($this->phone);

        $this->assertSame(VerificationResult::Verified, $this->service->verify($this->phone, $code));
    }

    #[Test]
    public function a_code_works_only_once(): void
    {
        $code = $this->service->issue($this->phone);

        $this->service->verify($this->phone, $code);

        $this->assertSame(VerificationResult::Expired, $this->service->verify($this->phone, $code));
    }

    #[Test]
    public function a_wrong_code_counts_against_the_attempt_limit(): void
    {
        $this->service->issue($this->phone);

        $this->assertSame(VerificationResult::Invalid, $this->service->verify($this->phone, '000000'));
        $this->assertSame(1, PhoneVerification::first()->attempts);
    }

    #[Test]
    public function too_many_wrong_attempts_lock_the_code(): void
    {
        $code = $this->service->issue($this->phone);

        for ($i = 0; $i < 5; $i++) {
            $this->service->verify($this->phone, '000000');
        }

        // ولو كان الرمز صحيحاً — القفل على الرمز لا على المحاولة
        $this->assertSame(VerificationResult::Locked, $this->service->verify($this->phone, $code));
    }

    #[Test]
    public function an_expired_code_is_rejected(): void
    {
        $this->service->issue($this->phone);

        $this->travel(11)->minutes();

        $this->assertSame(VerificationResult::Expired, $this->service->verify($this->phone, '000000'));
    }

    #[Test]
    public function issuing_a_new_code_invalidates_the_previous_one(): void
    {
        $first = $this->service->issue($this->phone);
        $second = $this->service->issue($this->phone);

        $this->assertSame(VerificationResult::Invalid, $this->service->verify($this->phone, $first));

        // نفس السجل الجديد، فالمحاولة الخاطئة أعلاه استهلكت واحدة
        $this->assertSame(VerificationResult::Verified, $this->service->verify($this->phone, $second));
    }

    #[Test]
    public function the_send_rate_is_capped(): void
    {
        $this->service->issue($this->phone);
        $this->service->issue($this->phone);
        $this->service->issue($this->phone);

        $this->expectException(RuntimeException::class);

        $this->service->issue($this->phone);
    }

    #[Test]
    public function verifying_through_the_route_marks_the_user(): void
    {
        $code = $this->service->issue($this->phone);

        $this->actingAs($this->user)
            ->post('/verify-phone', ['code' => $code])
            ->assertRedirect();

        $this->assertTrue($this->user->fresh()->hasVerifiedPhone());
    }

    #[Test]
    public function an_already_verified_user_is_sent_away_from_the_page(): void
    {
        $this->user->markPhoneAsVerified();

        $this->actingAs($this->user)
            ->get('/verify-phone')
            ->assertRedirect(route('search'));
    }
}
PHP;

$files['tests/Feature/Identity/PublishGateTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * بوابة النشر.
 *
 * الاختبار الأخير هو المهم: البوابة قابلة للتعطيل من config، ومفتاح
 * إعداد بلا اختبار يعمل حتى اليوم الذي تحتاجه فيه.
 */
class PublishGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'علي محمد',
            'phone' => '+967771234567',
            'email' => 'ali@example.test',
            'password' => Hash::make('password1234'),
        ]);
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/add')->assertRedirect(route('login'));
    }

    #[Test]
    public function an_unverified_account_is_sent_to_verify_its_phone(): void
    {
        $this->actingAs($this->user)
            ->get('/add')
            ->assertRedirect(route('phone.verify'));
    }

    #[Test]
    public function a_verified_account_reaches_the_form(): void
    {
        $this->user->markPhoneAsVerified();

        $this->actingAs($this->user)->get('/add')->assertOk();
    }

    #[Test]
    public function the_gate_can_be_switched_off_from_config(): void
    {
        config(['identity.require_phone_verification_to_publish' => false]);

        $this->actingAs($this->user)->get('/add')->assertOk();
    }

    #[Test]
    public function switching_the_gate_off_does_not_open_it_to_guests(): void
    {
        config(['identity.require_phone_verification_to_publish' => false]);

        $this->get('/add')->assertRedirect(route('login'));
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// الكتابة
// ─────────────────────────────────────────────────────────────────────────────

$written = 0;

foreach ($files as $relativePath => $contents) {
    $path = __DIR__ . '/' . $relativePath;
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fwrite(STDERR, "فشل إنشاء المجلد: {$directory}\n");
        exit(1);
    }

    if (is_file($path)) {
        copy($path, $path . '.bak');
    }

    if (file_put_contents($path, str_replace("\r\n", "\n", $contents)) === false) {
        fwrite(STDERR, "فشل الكتابة: {$relativePath}\n");
        exit(1);
    }

    echo "✓ {$relativePath}\n";
    $written++;
}

echo "\nكُتب {$written} ملفاً.\n\n";
echo "الخطوات:\n";
echo "  composer dump-autoload\n";
echo "  php artisan optimize:clear\n";
echo "  php artisan route:list\n";
echo "  php artisan test\n";
echo "  php artisan souq:check\n\n";
echo "أضف إلى .env إن أردت تعطيل بوابة الهاتف للعرض:\n";
echo "  REQUIRE_PHONE_VERIFICATION=false\n\n";
echo "الْتزامات مقترحة:\n";
echo "  feat(identity): normalize Yemeni phone numbers into one canonical form\n";
echo "  feat(identity): add registration and login by phone or email\n";
echo "  feat(identity): verify phones with a hashed, expiring, rate-limited code\n";
echo "  feat(catalog): require an account with a verified phone to publish\n";