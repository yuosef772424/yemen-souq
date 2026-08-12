<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * محادثة واحدة لكل (إعلان، مشترٍ) — والقيد في المخطط لا في الكود:
 * unique(listing_id, buyer_id).
 *
 * ولهذا «بدء محادثة» في الخدمة هو firstOrCreate لا create: زرّ يُضغط
 * مرتين، أو مشترٍ يعود بعد أسبوع، لا يجوز أن يُنتج خيطين للحديث نفسه.
 */
class Conversation extends Model
{
    protected $table = 'conversations';

    public $timestamps = false;

    /**
     * seller_id هنا لأن الخدمة تنسخه من الإعلان — ولا يُقرأ من الطلب
     * أبداً. لو قُرئ منه لاستطاع أي أحد إنشاء محادثة تنسب البيع لغيره.
     */
    protected $fillable = [
        'listing_id',
        'buyer_id',
        'seller_id',
        'last_message_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /** محادثات مستخدم بأي دور — القائمة الواحدة تجمع ما باع وما اشترى */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user): void {
            $inner->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        });
    }

    public function involves(User $user): bool
    {
        return $user->id === $this->buyer_id || $user->id === $this->seller_id;
    }

    /** الطرف الآخر — ما يُعرض في القائمة، لأن دور المستخدم يتغيّر بين خيط وخيط */
    public function counterpart(User $user): ?User
    {
        return $user->id === $this->buyer_id ? $this->seller : $this->buyer;
    }

    /**
     * عدّ غير المقروء لكل الخيوط في استعلام واحد.
     *
     * كان `unreadCountFor` يُنادى داخل حلقة العرض — خمسة عشر استعلاماً
     * في صفحة الرسائل، وقد وُصف في `ConversationResource` كثمنٍ مقبول
     * لأن الصفحة مرقَّمة. وهو مقبول ما دامت خمسة عشر، ولا سبب لدفعه:
     * `withCount` بشرطٍ على العلاقة يُنجزه في نفس استعلام القائمة.
     *
     * ولماذا يُنتقى معه معرّف المستخدم: العدد **يخصّ قارئاً بعينه**،
     * وقيمةٌ محسوبة لأحمد تُقرأ لخالد كذبٌ صامت. الصفّ يحمل لمن حُسب،
     * و`unreadCountFor` تتجاهله لغيره وتسأل القاعدة.
     */
    public function scopeWithUnreadCountFor(Builder $query, User $user): Builder
    {
        return $query
            ->withCount(['messages as unread_count' => function (Builder $inner) use ($user): void {
                $inner->where('sender_id', '!=', $user->id)->whereNull('read_at');
            }])
            ->addSelect(DB::raw($user->id.' as unread_count_for'));
    }

    public function unreadCountFor(User $user): int
    {
        if ($this->unread_count !== null && (int) $this->unread_count_for === $user->id) {
            return (int) $this->unread_count;
        }

        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();
    }
}