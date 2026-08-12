<?php

namespace App\Modules\Notifications\Models;

use App\Models\User;
use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * الإشعار الواحد — سجلٌّ دائم أولاً، وتسليمٌ ثانياً.
 *
 * ═══ الصفّ يُكتب دائماً، والدفع قد لا يقع ═══
 *
 * الجهاز مطفأ، أو الرمز منتهٍ، أو المستخدم أوقف الدفع — وفي كلٍّ منها
 * يجب أن يجد المستخدم الإشعار حين يفتح التطبيق. ولهذا الصفّ يُكتب
 * متزامناً مع الحدث، والدفع يُرسَل في طابور بعده. لو انعكس الترتيب
 * لصار وصول الإشعار مشروطاً بنجاح مزوّد خارجي.
 *
 * ═══ معنى الأعمدة الثلاثة الزمنية ═══
 *
 *   created_at — متى وقع الحدث
 *   sent_at    — متى غادر الإشعار إلى مزوّد الدفع فعلاً (null = لم يُدفع)
 *   read_at    — متى فتحه المستخدم
 *
 * وchannel ليس «أين يُعرض» بل **أبعد قناة بلغها**: الصفّ يبدأ in_app
 * دائماً، ويصير push حين ينجح الدفع. فقراءة الجدول وحدها تُجيب «كم
 * إشعاراً بقي داخل التطبيق ولم يصل صاحبه؟» — وهو أول سؤال يُسأل حين
 * يشتكي مستخدم أنه لم يُشعَر.
 *
 * ═══ data عقدٌ مع التطبيق ═══
 *
 * الحمولة تحمل ما يكفي للتنقّل بلا استعلام إضافي: entity_type وentity_id
 * وurl. التطبيق ينقر الإشعار فيفتح الشاشة مباشرة، بلا جولة إلى الخادم
 * ليعرف إلى أين يذهب — وهو الفرق بين إشعار يعمل بلا شبكة جيدة وإشعار لا
 * يعمل.
 */
class AppNotification extends Model
{
    protected $table = 'app_notifications';

    /** created_at وحده في المخطط — لا updated_at */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'dedupe_key',
        'title',
        'body',
        'data',
        'channel',
        'read_at',
        'sent_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'channel' => Channel::class,
            'data' => 'array',
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /** لا يُحرَّك تاريخ القراءة الأول — «متى رآه» يقع مرة */
    public function markRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->forceFill(['read_at' => now()])->save();
    }

    /**
     * وجهة النقر.
     *
     * تُقرأ من الحمولة لا تُشتقّ من النوع: الرابط يُبنى لحظة الإنشاء حيث
     * الكائن حاضر، واشتقاقه هنا يعني switch ثانياً على النوع يتقادم مع
     * أول تغيير في المسارات.
     */
    public function url(): ?string
    {
        $url = $this->data['url'] ?? null;

        return is_string($url) ? $url : null;
    }

    /**
     * الشكل الذي تستهلكه العملاء — التطبيق اليوم عبر الدفع، ووحدة API غداً.
     *
     * وجودها هنا لا في مورد API بقصد: الشكل عقدٌ يخصّ الإشعار نفسه، وبقاؤه
     * مع النموذج يعني أن الويب والدفع وAPI تقرأ حقلاً واحداً بنفس الاسم.
     * أول مرة يُنسخ هذا الشكل في موضعين يبدأ افتراقهما.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data ?? [],
            'read' => $this->isRead(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
