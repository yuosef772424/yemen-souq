<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * إعدادات الحساب — الشاشة التي كانت ناقصة كاملةً.
 *
 * أربعة أفعال، كلٌّ له حارسه: الاسم يُعدَّل بلا شرط، والرقم وكلمة المرور
 * والإغلاق تحتاج كلمة المرور الحالية. وأخطر ما يُختبر هنا ليس النجاح بل
 * ما يقع **بعده**: الرقم الجديد يبدأ غير موثَّق، والحساب المُغلق تُخفى
 * إعلاناته ويُحرَّر رقمه.
 */
class AccountSettingsTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->user = User::create([
            'name' => 'صاحب الحساب',
            'phone' => '+967771234567',
            'email' => 'owner@example.test',
            'password' => Hash::make('password1234'),
        ]);

        $this->user->markPhoneAsVerified();

        UserProfile::create([
            'user_id' => $this->user->id,
            'display_name' => 'صاحب الحساب',
        ]);
    }

    // ─── الوصول ───

    #[Test]
    public function a_guest_is_sent_to_login(): void
    {
        $this->get(route('account.settings'))->assertRedirect(route('login'));
    }

    /**
     * الصفحة مفتوحة لغير الموثَّق بقصد.
     *
     * من كتب رقمه خطأً لا يستطيع توثيقه، وحجب صفحة تصحيح الرقم خلف
     * التوثيق يقفل عليه الباب من الداخل.
     */
    #[Test]
    public function an_unverified_account_still_reaches_its_settings(): void
    {
        $unverified = User::create([
            'name' => 'غير موثَّق',
            'phone' => '+967772222222',
            'email' => 'unverified@example.test',
            'password' => Hash::make('password1234'),
        ]);

        $this->actingAs($unverified)->get(route('account.settings'))->assertOk();
    }

    // ─── البيانات ───

    #[Test]
    public function the_owner_changes_their_name_and_display_name(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.profile.update'), $this->profilePayload([
                'name' => 'اسم جديد',
                'display_name' => 'معرض الأمانة',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('اسم جديد', $this->user->fresh()->name);
        $this->assertSame('معرض الأمانة', $this->user->fresh()->displayName());
    }

    /** حساب بلا صفّ عرض — بذرة أو factory — يُنشأ له واحد لا يسقط عليه */
    #[Test]
    public function a_user_without_a_profile_row_gets_one(): void
    {
        $this->user->profile()->delete();

        $this->actingAs($this->user->fresh())
            ->put(route('account.profile.update'), $this->profilePayload(['display_name' => 'اسم معروض']))
            ->assertSessionHasNoErrors();

        $this->assertSame('اسم معروض', $this->user->fresh()->profile->display_name);
    }

    #[Test]
    public function a_business_account_must_name_its_business(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.profile.update'), $this->profilePayload(['is_business' => '1']))
            ->assertSessionHasErrorsIn('profile', 'business_name');
    }

    #[Test]
    public function an_email_taken_by_another_account_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($this->user)
            ->put(route('account.profile.update'), $this->profilePayload(['email' => 'taken@example.test']))
            ->assertSessionHasErrorsIn('profile', 'email');
    }

    /** والبريد الحالي لا يصطدم بنفسه */
    #[Test]
    public function keeping_the_same_email_is_not_a_collision(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.profile.update'), $this->profilePayload())
            ->assertSessionHasNoErrors();
    }

    /** الملف الشخصي لا يرفع صاحبه إلى مدير */
    #[Test]
    public function role_and_status_are_not_writable_from_the_form(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.profile.update'), $this->profilePayload([
                'role' => 'admin',
                'status' => 'banned',
            ]));

        $fresh = $this->user->fresh();

        $this->assertSame('member', $fresh->role);
        $this->assertSame('active', $fresh->status);
    }

    // ─── كلمة المرور ───

    #[Test]
    public function changing_the_password_requires_the_current_one(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password-9876',
                'password_confirmation' => 'new-password-9876',
            ])
            ->assertSessionHasErrorsIn('password', 'current_password');

        $this->assertTrue(Hash::check('password1234', $this->user->fresh()->password));
    }

    #[Test]
    public function the_password_changes_and_the_remember_token_rotates(): void
    {
        $before = $this->user->remember_token;

        $this->actingAs($this->user)
            ->put(route('account.password.update'), [
                'current_password' => 'password1234',
                'password' => 'new-password-9876',
                'password_confirmation' => 'new-password-9876',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $this->user->fresh();

        $this->assertTrue(Hash::check('new-password-9876', $fresh->password));
        $this->assertNotSame($before, $fresh->remember_token);
    }

    // ─── الرقم ───

    #[Test]
    public function changing_the_phone_needs_the_password(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.phone.update'), [
                'phone' => '0773333333',
                'current_password' => 'wrong-password',
            ])
            ->assertSessionHasErrorsIn('phone', 'current_password');

        $this->assertSame('+967771234567', $this->user->fresh()->phone);
    }

    /**
     * أخطر ما في هذا الملف: الرقم الجديد يبدأ غير موثَّق.
     *
     * بلا هذا يصير تغيير الرقم بابَ التفافٍ على بوابة النشر كلها —
     * يوثّق صاحب الحساب رقماً يملكه ثم يبدّله بأي رقم.
     */
    #[Test]
    public function a_new_phone_starts_unverified_and_gets_a_code(): void
    {
        $this->actingAs($this->user)
            ->put(route('account.phone.update'), [
                'phone' => '0773333333',
                'current_password' => 'password1234',
            ])
            ->assertRedirect(route('phone.verify'));

        $fresh = $this->user->fresh();

        $this->assertSame('+967773333333', $fresh->phone);
        $this->assertNull($fresh->phone_verified_at);
        $this->assertFalse($fresh->hasVerifiedPhone());

        $this->assertSame(1, PhoneVerification::where('phone', '+967773333333')->count());
    }

    /** والصيغة تُطبَّع قبل الفحص، وإلا مرّ رقمٌ مسجَّل بصيغة أخرى */
    #[Test]
    public function a_phone_owned_by_another_account_is_refused_in_any_format(): void
    {
        User::factory()->create(['phone' => '+967774444444']);

        foreach (['0774444444', '+967774444444', '00967774444444'] as $format) {
            $this->actingAs($this->user)
                ->put(route('account.phone.update'), [
                    'phone' => $format,
                    'current_password' => 'password1234',
                ])
                ->assertSessionHasErrorsIn('phone', 'phone');
        }

        $this->assertSame('+967771234567', $this->user->fresh()->phone);
    }

    // ─── الإغلاق ───

    #[Test]
    public function closing_the_account_needs_the_password(): void
    {
        $this->actingAs($this->user)
            ->delete(route('account.destroy'), ['current_password' => 'wrong-password'])
            ->assertSessionHasErrorsIn('deleteAccount', 'current_password');

        $this->assertNull($this->user->fresh()->deleted_at);
    }

    #[Test]
    public function closing_the_account_signs_out_and_soft_deletes(): void
    {
        $this->actingAs($this->user)
            ->delete(route('account.destroy'), ['current_password' => 'password1234'])
            ->assertRedirect(route('search'));

        $this->assertGuest();
        $this->assertNull(User::find($this->user->id));
        $this->assertNotNull(User::withTrashed()->find($this->user->id)->deleted_at);
    }

    /**
     * وإعلاناته تغادر السوق معه.
     *
     * `cascadeOnDelete` على المفتاح الأجنبي يعمل عند الحذف الحقيقي
     * وحده، والإغلاق حذفٌ منطقي — فبلا `UserObserver` تبقى الإعلانات
     * معروضةً برقم تواصلٍ لا يردّ عليه أحد.
     */
    #[Test]
    public function the_listings_of_a_closed_account_leave_the_market(): void
    {
        $listing = $this->listingFor($this->user);

        $this->actingAs($this->user)
            ->delete(route('account.destroy'), ['current_password' => 'password1234']);

        $this->assertNull(Listing::find($listing->id));
        $this->assertNotNull(Listing::withTrashed()->find($listing->id)->deleted_at);
    }

    /** والرقم يُحرَّر ليعود صاحبه به — القيد الفريد لا يعرف المحذوف */
    #[Test]
    public function the_phone_is_released_so_its_owner_can_return(): void
    {
        $this->actingAs($this->user)
            ->delete(route('account.destroy'), ['current_password' => 'password1234']);

        $this->assertNull(User::withTrashed()->find($this->user->id)->phone);

        $returning = User::create([
            'name' => 'العائد',
            'phone' => '+967771234567',
            'email' => 'again@example.test',
            'password' => Hash::make('password1234'),
        ]);

        $this->assertSame('+967771234567', $returning->phone);
    }

    #[Test]
    public function a_closed_account_can_no_longer_sign_in(): void
    {
        $this->actingAs($this->user)
            ->delete(route('account.destroy'), ['current_password' => 'password1234']);

        $this->post(route('login'), [
            'identifier' => 'owner@example.test',
            'password' => 'password1234',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    // ─── الصورة ───

    /**
     * `avatar_path` كان عموداً قائماً بلا مسارٍ يكتبه — آخر ما بقي من
     * عائلة «السكيمة تَعِد بما لا ينفّذه الكود».
     */
    #[Test]
    public function an_avatar_is_stored_as_a_square_webp(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)
            ->put(route('account.profile.update'), $this->profilePayload([
                'avatar' => UploadedFile::fake()->image('me.jpg', 1200, 800),
            ]))
            ->assertSessionHasNoErrors();

        $path = $this->user->fresh()->profile->avatar_path;

        $this->assertNotNull($path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);

        $image = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame($image[0], $image[1], 'الصورة تُقصّ مربّعة عند الحفظ لا في المتصفح.');
    }

    /** والاستبدال يحذف الملف القديم — وإلا تراكمت صور لا يشير إليها شيء */
    #[Test]
    public function replacing_an_avatar_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)->put(route('account.profile.update'), $this->profilePayload([
            'avatar' => UploadedFile::fake()->image('one.jpg', 400, 400),
        ]));

        $first = $this->user->fresh()->profile->avatar_path;

        $this->actingAs($this->user)->put(route('account.profile.update'), $this->profilePayload([
            'avatar' => UploadedFile::fake()->image('two.jpg', 400, 400),
        ]));

        $second = $this->user->fresh()->profile->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function removing_an_avatar_clears_the_column_and_the_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)->put(route('account.profile.update'), $this->profilePayload([
            'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
        ]));

        $path = $this->user->fresh()->profile->avatar_path;

        $this->actingAs($this->user)
            ->put(route('account.profile.update'), $this->profilePayload(['remove_avatar' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->user->fresh()->profile->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    /** وملفٌ ليس صورة يُرفض بفحص المحتوى لا الامتداد */
    #[Test]
    public function a_file_that_is_not_an_image_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)
            ->put(route('account.profile.update'), $this->profilePayload([
                'avatar' => UploadedFile::fake()->create('malware.jpg', 40, 'application/pdf'),
            ]))
            ->assertSessionHasErrorsIn('profile', 'avatar');

        $this->assertNull($this->user->fresh()->profile->avatar_path);
    }

    // ─── مساعد ───

    private function profilePayload(array $overrides = []): array
    {
        return [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'display_name' => 'صاحب الحساب',
            ...$overrides,
        ];
    }
}
