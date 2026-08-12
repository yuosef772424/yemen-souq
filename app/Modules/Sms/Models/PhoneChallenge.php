<?php

namespace App\Modules\Sms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneChallenge extends Model
{
    protected $table = 'phone_challenges';

    /** created_at وحده في المخطط — لا updated_at */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'phone',
        'token_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'created_at',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * التحدّي الوحيد القابل للاستهلاك الآن لهذا الرقم.
     *
     * `latest('id')` لأن إصدار تحدٍّ جديد يستهلك السابق — نفس مبدأ
     * `PhoneVerification::usableFor`. وبلا ذلك يبقى تحدٍّ قديم صالحاً بعد
     * طلب واحدٍ جديد، فيتضاعف سطح الهجوم بكل إعادة عرض.
     */
    public function scopeUsableFor(Builder $query, string $phone): Builder
    {
        return $query
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>=', now())
            ->latest('id');
    }
}
