<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جهاز واحد لمستخدم واحد.
 *
 * ═══ لماذا في Identity لا في Notifications ═══
 *
 * الجدول يُنشأ في هجرة الهوية، وأعمدته أوسع من الدفع: device_uuid
 * وplatform وlast_seen_at تصف جلسة جهاز، وpush_token عمود nullable
 * واحد بينها. الجهاز يخصّ الحساب، والإشعارات مستهلكٌ له لا مالكه —
 * ووحدة الإشعارات تقرؤه ولا تعرّفه.
 *
 * ═══ device_uuid يصنعه العميل ═══
 *
 * القيد unique(user_id, device_uuid) يعني أن التطبيق يولّد معرّفاً ثابتاً
 * لتنصيبه ويرسله في كل تسجيل. البديل — التعريف برمز الدفع نفسه — ينكسر
 * لأن رمز الدفع يتغيّر: تدويره الدوري ينتج صفاً جديداً في كل مرة،
 * فيتراكم للمستخدم الواحد عشرون صفاً لجهاز واحد ويصله الإشعار عشرين مرة.
 *
 * ═══ الرمز قد يكون null ═══
 *
 * متصفّح لا يدعم الدفع، أو مستخدم رفض إذن الإشعارات، أو رمز حُذف بعد
 * أن رفضه المزوّد. الصفّ يبقى — فهو سجلّ جهاز، لا سجلّ رمز.
 */
class UserDevice extends Model
{
    protected $table = 'user_devices';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'device_uuid',
        'platform',
        'push_token',
        'last_seen_at',
        'created_at',
    ];

    protected $hidden = [
        'push_token',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** الأجهزة التي يمكن أن يصلها دفعٌ فعلاً */
    public function scopePushable(Builder $query): Builder
    {
        return $query->whereNotNull('push_token');
    }
}
