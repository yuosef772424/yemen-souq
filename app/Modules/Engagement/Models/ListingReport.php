<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بلاغ على إعلان.
 *
 * لماذا الموديل في وحدة التفاعل لا في وحدة التحكم: الجدول
 * listing_reports أنشأته هجرة Engagement، والمُبلِّغ مستخدم عادي لا
 * مشرف — فالبلاغ فعل تفاعل، ومعالجته وحدها عمل إداري. لو وُضع الموديل
 * في Admin لصارت وحدة التفاعل عاجزة عن استقبال بلاغ بلا وجود لوحة
 * تحكم، وهذا عكس معنى الوحدة المستقلة.
 *
 * وwithTrashed على العلاقة listing مقصود: أول ما يفعله المشرف عند
 * تنفيذ بلاغ هو إخفاء الإعلان — ولو أخفت العلاقة الصفوف المحذوفة
 * لصار البلاغ المنفَّذ بلا إعلان يُعرض، فلا يمكن مراجعة القرار.
 */
class ListingReport extends Model
{
    protected $table = 'listing_reports';

    public $timestamps = false;

    protected $fillable = ['listing_id', 'reporter_id', 'reason', 'details', 'status', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'reviewing']);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'reviewing'], true);
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'spam' => 'إعلان مكرر أو دعائي',
            'fraud' => 'نصب أو احتيال',
            'wrong_category' => 'تصنيف خاطئ',
            'prohibited' => 'سلعة ممنوعة',
            'duplicate' => 'نسخة مكررة',
            default => 'أخرى',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open' => 'جديد',
            'reviewing' => 'قيد المراجعة',
            'actioned' => 'نُفِّذ',
            'dismissed' => 'مصروف',
            default => $this->status,
        };
    }
}
