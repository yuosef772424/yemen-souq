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