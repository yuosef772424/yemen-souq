<?php

namespace App\Models;

use App\Modules\Analytics\Models\TrackingPreference;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Identity\Models\UserDevice;
use App\Modules\Identity\Models\UserProfile;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * كل ما تستدعيه وحدة الهوية من هذا النموذج، في مكان واحد.
 *
 * أُضيفت isActive() هنا: كان LoginController يناديها وهي غير موجودة،
 * فيُلقى BadMethodCallException *بعد* نجاح Auth::attempt — أي أن
 * المستخدم يُسجَّل دخوله ثم يرى صفحة خطأ 500. ولذلك بدت أعراضها
 * متناقضة: assertAuthenticatedAs ينجح وassertRedirect يسقط في نفس
 * المسار. هذا هو نوع العطل الذي يضيّع ساعة قبل أن يُفهم.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * phone قابل للتعيين الجماعي، وphone_verified_at وstatus ليسا كذلك
     * بقصد: التوثيق والإيقاف قرارا نظامٍ لا حقلا نموذجٍ يملؤهما المستخدم.
     * يُكتبان بـforceFill من الموضع الذي يملك القرار.
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ---- العلاقات ----

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function trackingPreferences(): HasOne
    {
        return $this->hasOne(TrackingPreference::class);
    }

    /**
     * appNotifications لا notifications: سمة Notifiable تحجز الاسم الأخير
     * لجدول لارافل الخاص بها، وإعادة تعريفه تكسر السمة بلا خطأ ظاهر.
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    // ---- الهوية ----

    /**
     * الاسم المعروض يأتي من الملف الشخصي، ويرجع إلى name عند غيابه.
     * القالب يناديها لكل مستخدم مسجَّل، فلا يجوز أن تفترض وجود صف
     * user_profiles — مستخدم من factory أو seeder لا يملك واحداً.
     */
    public function displayName(): string
    {
        return $this->profile?->display_name ?: $this->name;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone !== null && $this->phone_verified_at !== null;
    }

    public function markPhoneAsVerified(): void
    {
        if ($this->phone_verified_at !== null) {
            return;
        }

        $this->forceFill(['phone_verified_at' => now()])->save();
    }

    /**
     * الحساب العامل.
     *
     * القيمة الافتراضية في المخطط 'active'، لكن الشرط مكتوب صراحةً على
     * 'active' لا على «ليس موقوفاً»: إضافة حالة رابعة لاحقاً
     * (مثل pending_review) يجب أن تُقصي صاحبها حتى يُقرَّر فيها، لا أن
     * تمرّ لأنها ليست في قائمة الممنوعات.
     */
    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }
}