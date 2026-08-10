<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جدول بمفتاح مركّب بلا id.
 *
 * ولهذا $incrementing = false و$primaryKey فارغ: Eloquent يفترض عمود id
 * تلقائياً، فيبني استعلامات على عمود غير موجود. القيد المركّب هو ما يمنع
 * التكرار في القاعدة نفسها — لا شرط في الكود.
 */
class Favorite extends Model
{
    protected $table = 'favorites';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'user_id',
        'listing_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}