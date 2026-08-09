<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * الملف الشخصي — جدول منفصل عن users بقصد.
 *
 * users يحمل ما تحتاجه المصادقة فقط؛ وهذا يحمل ما يُعرَض. الفصل يعني
 * أن قراءة صفحة إعلان لا تجلب أعمدة الحساب الحساسة، وأن إضافة حقل
 * عرض جديد لا تمس جدول المصادقة.
 *
 * trust_score محسوب ولا يُحرَّر يدوياً — لذلك هو خارج fillable.
 * الحساب لم يُبنَ بعد؛ العمود ينتظر SellerTrustScorer.
 */
class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'display_name',
        'name_normalized',
        'avatar_path',
        'bio',
        'location_id',
        'preferred_currency',
        'preferred_locale',
        'is_business',
        'business_name',
    ];

    protected function casts(): array
    {
        return [
            'is_business' => 'boolean',
            'trust_score' => 'decimal:2',
            'listings_count' => 'integer',
        ];
    }

    /**
     * name_normalized يُملأ تلقائياً — بنفس منطق ListingObserver.
     * لو تُرك للمتحكم لنُسي في أول مسار آخر يُنشئ ملفاً شخصياً
     * (لوحة إدارة، استيراد، اختبار)، فيصبح البحث عن البائع بالاسم عاجزاً.
     */
    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if ($profile->isDirty('display_name')) {
                $profile->name_normalized = app(ArabicNormalizer::class)
                    ->normalize($profile->display_name);
            }
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