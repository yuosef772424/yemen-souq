<?php

namespace App\Modules\Api\Http\Controllers;

use App\Models\User;
use App\Modules\Analytics\Models\TrackingPreference;
use App\Modules\Api\Http\Resources\UserResource;
use App\Modules\Identity\Http\Requests\UpdatePasswordRequest;
use App\Modules\Identity\Http\Requests\UpdatePhoneRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Models\UserProfile;
use App\Modules\Identity\Services\AvatarService;
use App\Modules\Identity\Services\PhoneVerificationService;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * الحساب من التطبيق — نفس أفعال شاشة الإعدادات في الويب.
 *
 * ═══ الطلبات مُعادة الاستعمال، والمتحكّم لا ═══
 *
 * `UpdateProfileRequest` و`UpdatePasswordRequest` و`UpdatePhoneRequest`
 * كلها من وحدة الهوية بلا تعديل: القواعد قرارٌ، والقرار لا يُنسخ. أما
 * ما يفعله متحكّم الويب بعد التحقق فكلّه خاصٌّ بالمتصفح — إعادة توليد
 * الجلسة، والتحويل إلى صفحة، وحزم الأخطاء المسمّاة. ونقطةٌ بلا حالة
 * لا تحتاج شيئاً منها.
 *
 * ═══ وثلاثة فروق حقيقية عن الويب ═══
 *
 * ١. **تغيير كلمة المرور يُبطل بقية الرموز.** في المتصفح تكفي جلسةٌ
 *    واحدة تُعاد؛ وهنا لكل جهاز رمزٌ مستقل، ومن غيّر كلمته لأنه يشكّ
 *    في جهازٍ آخر يجب أن يخرج ذلك الجهاز فعلاً. والرمز الحالي يبقى —
 *    وإلا خرج صاحب الطلب من جهازه هو.
 *
 * ٢. **رمز التوثيق يعود في الاستجابة** حين تعرضه القناة (بيئة العرض)،
 *    لأن التطبيق لا يملك «رسالة جلسة» يقرأ منها.
 *
 * ٣. **الإغلاق يمحو كل الرموز.** لا جلسة تُبطَل هنا، والرمز هو الباب.
 */
class AccountController
{
    public function __construct(
        private readonly PhoneVerificationService $verification,
        private readonly PreferenceResolver $preferences,
        private readonly AvatarService $avatars,
    ) {}

    // ─── البيانات ───

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
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

        return response()->json([
            'data' => new UserResource($user->fresh()),
            'message' => 'حُفظت بيانات حسابك.',
        ]);
    }

    /**
     * الصورة بمسار مستقل، وبـPOST لا PUT.
     *
     * ليست دمجاً في `PUT /me/profile` لسببٍ في PHP لا في التصميم: **لا
     * يُحلَّل جسد multipart إلا على POST**. فطلب PUT يحمل صورة يصل إلى
     * `$request->file()` فارغاً — بلا خطأ، والبيانات تُحفظ والصورة
     * تختفي في الطريق. نفس ما دفع `POST /me/listings/{listing}` إلى
     * مخالفة الدلالة.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => [
                'required', 'file', 'image',
                'mimes:jpeg,jpg,png,webp',
                'max:'.AvatarService::MAX_KB,
                'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000',
            ],
        ], [
            'avatar.required' => 'أرفق صورة.',
            'avatar.image' => 'الملف المرفوع ليس صورة صالحة.',
            'avatar.max' => 'حجم الصورة يجب أن يكون أقل من 4 ميجابايت.',
        ]);

        $this->avatars->replace($request->user(), $request->file('avatar'));

        return response()->json([
            'data' => new UserResource($request->user()->fresh()),
            'message' => 'حُفظت صورتك.',
        ]);
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $this->avatars->remove($request->user());

        return response()->json([
            'data' => new UserResource($request->user()->fresh()),
            'message' => 'حُذفت صورتك.',
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        // كل الأجهزة الأخرى تخرج — والجهاز الحالي يبقى
        $revoked = $user->tokens()
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();

        return response()->json([
            'message' => 'غُيّرت كلمة المرور.',
            'revoked_tokens' => $revoked,
        ]);
    }

    /**
     * الرقم الجديد يبدأ غير موثَّق — نفس قرار الويب وسببه: القبول بلا
     * إعادة توثيق يجعل تغيير الرقم بابَ التفافٍ على بوابة النشر كلها.
     */
    public function updatePhone(UpdatePhoneRequest $request): JsonResponse
    {
        $user = $request->user();
        $phone = $request->validated('phone');

        if ($phone === $user->phone && $user->hasVerifiedPhone()) {
            return response()->json([
                'data' => new UserResource($user),
                'message' => 'هذا هو رقمك الموثَّق بالفعل.',
            ]);
        }

        $user->forceFill([
            'phone' => $phone,
            'phone_verified_at' => null,
        ])->save();

        // فشل الإرسال لا يُلغي التغيير: الرقم تغيّر، والرمز يُطلب ثانيةً
        // من /auth/phone/send
        $code = null;

        try {
            $code = $this->verification->issue($phone);
        } catch (RuntimeException) {
            $code = null;
        }

        return response()->json([
            'data' => new UserResource($user->fresh()),
            'verification_code' => $code,
            'message' => 'غُيّر رقمك. وثّقه لتتمكن من النشر.',
        ]);
    }

    // ─── تفضيلات الإشعارات ───

    /**
     * الشكل مصفوفة كائنات لا خريطة متداخلة.
     *
     * `[{type, label, mandatory, channels:{…}}]` يحمل معه ما يعرضه
     * التطبيق: التسمية العربية وعلامة الإجبار. والخريطة المجرّدة تجبر
     * كل عميل على أن يحمل نسخته من التسميات — فتتفرّق عن الخادم عند
     * إضافة نوع.
     */
    public function notificationPreferences(Request $request): JsonResponse
    {
        $current = $this->preferences->allFor($request->user());

        $types = [];

        foreach (NotificationType::cases() as $type) {
            $channels = [];

            foreach ($this->implementedChannels() as $channel) {
                $channels[$channel->value] = $current[$type->value][$channel->value];
            }

            $types[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'mandatory' => $type->isMandatory(),
                'channels' => $channels,
            ];
        }

        return response()->json([
            'data' => $types,
            'channels' => array_map(
                static fn (Channel $channel): array => [
                    'value' => $channel->value,
                    'label' => $channel->label(),
                ],
                array_values($this->implementedChannels()),
            ),
        ]);
    }

    /**
     * الحفظ يمرّ على كل نوع لا على المُرسَل وحده — نفس قرار الويب:
     * الغياب يعني «أطفئ» لا «لا تغيّر». وعميلٌ يرسل نوعاً واحداً كان
     * سيُطفئ الباقي بلا قصد، ولذلك يُشترط إرسال الشكل كاملاً.
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['array'],
        ], [
            'preferences.required' => 'أرسل التفضيلات كاملةً — الغياب يُقرأ إطفاءً.',
        ]);

        $submitted = $request->input('preferences', []);
        $user = $request->user();

        foreach (NotificationType::cases() as $type) {
            $channels = [];

            foreach ($this->implementedChannels() as $channel) {
                $channels[$channel->value] = $type->isMandatory() && $channel === Channel::InApp
                    ? true
                    : filter_var(
                        $submitted[$type->value][$channel->value] ?? false,
                        FILTER_VALIDATE_BOOLEAN
                    );
            }

            $this->preferences->save($user, $type, $channels);
        }

        return $this->notificationPreferences($request);
    }

    // ─── الخصوصية ───

    public function privacy(Request $request): JsonResponse
    {
        $preference = $request->user()->trackingPreferences;

        return response()->json([
            'data' => [
                // غياب الصفّ = الافتراض مسموح، كما تقرؤه EventRecorder
                'allow_analytics' => (bool) ($preference?->allow_analytics ?? true),
                'allow_personalization' => (bool) ($preference?->allow_personalization ?? true),
            ],
        ]);
    }

    public function updatePrivacy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'allow_analytics' => ['required', 'boolean'],
            'allow_personalization' => ['required', 'boolean'],
        ]);

        TrackingPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            [...$data, 'updated_at' => now()],
        );

        if (! $data['allow_personalization']) {
            DB::table('user_category_affinity')
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        return $this->privacy($request);
    }

    // ─── الإغلاق ───

    /**
     * إغلاق الحساب — وشرحه الكامل في متحكّم الويب.
     *
     * الفرق الوحيد أن الباب هنا رمزٌ لا جلسة: كل الرموز تُمحى، فلا يبقى
     * جهازٌ يعمل بحسابٍ أُغلق.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(
            ['current_password' => ['required', 'current_password']],
            [
                'current_password.required' => 'أدخل كلمة المرور لتأكيد الإغلاق.',
                'current_password.current_password' => 'كلمة المرور غير صحيحة.',
            ],
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

        return response()->json(['message' => 'أُغلق حسابك.']);
    }

    /** @return list<Channel> */
    private function implementedChannels(): array
    {
        return array_values(array_filter(
            Channel::cases(),
            static fn (Channel $channel): bool => $channel->isImplemented(),
        ));
    }
}
