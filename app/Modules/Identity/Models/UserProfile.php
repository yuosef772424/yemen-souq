<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صف العرض لكل مستخدم.
 *
 * RegisterController كان يستورد هذا الصنف وينشئه، والصنف غير موجود —
 * فكل تسجيل جديد ينتهي بـClass not found. ولو وُجد الصنف بلا الخطاف
 * أدناه لانتهى بخطأ SQL: name_normalized عمود NOT NULL والمتحكّم
 * يمرّر display_name فقط.
 *
 * لماذا التطبيع في saving وليس في المتحكّم: نفس مبدأ ListingObserver —
 * أي مسار يحفظ صفاً (تسجيل، seeder، اختبار، لوحة إدارة لاحقاً) يمرّ
 * من هنا، فلا يبقى العمود فارغاً بصمت في مسار نسي أحدهم تعديله.
 */
class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'display_name', 'avatar_path', 'bio',
        'location_id', 'preferred_currency', 'preferred_locale',
        'is_business', 'business_name',
    ];

    /**
     * trust_score وlistings_count محسوبان — غيابهما عن fillable مقصود
     * تماماً كغياب price_in_base عن fillable في Listing.
     */
    protected function casts(): array
    {
        return [
            'is_business' => 'boolean',
            'trust_score' => 'decimal:2',
            'listings_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            $profile->name_normalized = app(ArabicNormalizer::class)
                ->normalize($profile->display_name);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}