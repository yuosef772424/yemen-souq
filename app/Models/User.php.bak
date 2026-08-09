<?php

namespace App\Models;

use App\Modules\Analytics\Models\TrackingPreference;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Identity\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * الحساب.
 *
 * قناة التوثيق هي الهاتف لا البريد: في السوق اليمني يُبدَّل البريد ولا
 * يُبدَّل الرقم، وكثير من المعلنين لا بريد لهم عملياً. والرقم هو أيضاً
 * وسيلة التواصل التي تظهر في الإعلان، فتوثيقه يخدم المشتري لا التسجيل فقط.
 *
 * لإضافة تأكيد البريد لاحقاً — عمود email_verified_at موجود أصلاً:
 *   1. أضف: implements \Illuminate\Contracts\Auth\MustVerifyEmail
 *   2. أضف ->middleware('verified') على المسارات التي تريد حجبها
 *   3. سجّل مسارات لارافيل الجاهزة للتحقق
 * لا يحتاج هجرة ولا تغييراً في أي مكان آخر.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * status غائب عن هذه القائمة بقصد: من يملك تعديل حالته يملك رفع الحظر
     * عن نفسه. الحالة تُغيَّر بكود صريح، لا بتمرير حقل في طلب HTTP.
     * وphone_verified_at كذلك — يُكتب بعد التحقق فقط.
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

    /**
     * تفضيلات التتبع — يقرأها EventRecorder لاحترام الانسحاب.
     * اسم الدالة يجب أن يبقى trackingPreferences: الوحدة تستدعيه بهذا الاسم.
     */
    public function trackingPreferences(): HasOne
    {
        return $this->hasOne(TrackingPreference::class, 'user_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    // ---- الحالة ----

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function markPhoneAsVerified(): void
    {
        // خارج fillable، فيُكتب هنا صراحةً
        $this->forceFill(['phone_verified_at' => now()])->save();
    }

    /** الاسم المعروض في الإعلانات — من الملف الشخصي إن وُجد */
    public function displayName(): string
    {
        return $this->profile?->display_name ?? $this->name;
    }
}