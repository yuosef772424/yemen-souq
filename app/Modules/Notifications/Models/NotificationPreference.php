<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تفضيل مستخدم واحد لنوع واحد.
 *
 * ═══ غياب الصفّ ليس «لا» ═══
 *
 * المفتاح الأساسي مركّب (user_id, notification_type) ولا يوجد صفّ
 * افتراضي يُنشأ عند التسجيل. فالمستخدم الذي لم يفتح الإعدادات قط —
 * وهو الأغلبية — لا يملك صفاً، وقراءة غيابه كرفض تُسكت النظام عن الجميع.
 *
 * الغياب يعني «خُذ بالافتراض المعلَن في NotificationType»، والصفّ يُكتب
 * فقط حين يغيّر صاحبه شيئاً. البديل — إنشاء خمسة صفوف لكل مستخدم عند
 * التسجيل — يعني أن تغيير الافتراض لاحقاً لا يمسّ أحداً من القدامى، لأن
 * صفوفهم تحمل الافتراض القديم مجمَّداً.
 *
 * ═══ لا مفتاح تصاعدي ═══
 *
 * incrementing = false وkeyType نصّي: المفتاح مركّب، وEloquent بلا هذين
 * السطرين يفترض عمود id ويبني تحديثاً على ‎where id = ?‎ — عمود غير
 * موجود.
 */
class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'notification_type',
        'in_app',
        'push',
        'sms',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'notification_type' => NotificationType::class,
            'in_app' => 'boolean',
            'push' => 'boolean',
            'sms' => 'boolean',
            'email' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(Channel $channel): bool
    {
        return (bool) $this->getAttribute($channel->value);
    }

    /**
     * صفٌّ كامل من الافتراضات — يُستعمل عند أول حفظ للإعدادات.
     *
     * الحفظ الجزئي مرفوض: صفّ فيه push فقط يترك بقية الأعمدة على
     * افتراض قاعدة البيانات، وهو افتراض مكتوب في الهجرة لا في
     * NotificationType — فيفترق مصدرا الحقيقة عند أول تعديل.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(User $user, NotificationType $type): array
    {
        $row = [
            'user_id' => $user->id,
            'notification_type' => $type->value,
        ];

        foreach (Channel::cases() as $channel) {
            $row[$channel->value] = $type->defaultFor($channel);
        }

        return $row;
    }
}
