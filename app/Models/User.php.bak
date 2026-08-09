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
 * كان هذا الملف نسخة لارافل الافتراضية بلا تعديل، بينما تعتمد عليه
 * وحدة الهوية بأربع طرق غير موجودة: phone في fillable، وdisplayName،
 * وhasVerifiedPhone، وmarkPhoneAsVerified، وعلاقة trackingPreferences
 * التي يقرأها EventRecorder لكل مستخدم مسجَّل.
 *
 * الأعمدة موجودة في هجرة الهوية (000200) منذ البداية — الناقص كان
 * النموذج وحده. ولهذا كانت اختبارات الهوية تسقط كلها بينما تبدو
 * الهجرة سليمة.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * phone قابل للتعيين الجماعي، وphone_verified_at ليس كذلك بقصد:
     * التوثيق لا يجوز أن يأتي من طلب HTTP. يُكتب بـforceFill في
     * markPhoneAsVerified فقط، بعد التحقق من الرمز.
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

    // ---- الهوية ----

    /**
     * الاسم المعروض يأتي من الملف الشخصي، ويرجع إلى name عند غيابه.
     *
     * القالب يستدعي هذه الطريقة في كل صفحة لمستخدم مسجَّل، فلا يجوز
     * أن تفترض وجود صف user_profiles — مستخدم أُنشئ من factory أو من
     * seeder لا يملك واحداً.
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
}