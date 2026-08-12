<?php

namespace App\Modules\Sms\Models;

use App\Modules\Sms\Enums\DeliveryChannel;
use App\Modules\Sms\Enums\SmsStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * صفٌّ واحد في طابور الرسائل الصادرة.
 *
 * ═══ الأعمدة الحسّاسة خارج fillable ═══
 *
 * `status` و`attempts` و`claim_token` وكل أعمدة السحب والتسليم غائبة
 * بقصد — بنفس منطق `Listing`: هذه قراراتُ خدمةٍ لا حقولُ نموذجٍ يملؤها
 * مستدعٍ. الكاتب الوحيد لها هو `SmsOutbox`، وحصرها فيه هو ما يجعل
 * الحالات الخمس تعني ما تقوله.
 */
class SmsMessage extends Model
{
    protected $table = 'sms_messages';

    protected $fillable = [
        'to',
        'body',
        'purpose',
        'channel',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SmsStatus::class,
            'channel' => DeliveryChannel::class,
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * القابلة للسحب: في الطابور، ولم يفت أجلها.
     *
     * الأجل يُفحص هنا لا في المنظّف وحده — نفس حجّة `scopeNotExpired`
     * في `Listing`: المنظّف قد يتأخر أو يتوقف، وحينها يسحب الهاتف رسالةً
     * ميتة ويرسل رمزاً لن يعمل. الشرط الزمني صحيح دائماً بلا اعتماد على
     * مجدول.
     */
    public function scopeClaimable(Builder $query, DeliveryChannel $channel): Builder
    {
        return $query
            // القناة أولاً لتطابق ترتيب الفهرس sms_channel_claim_idx
            ->where('channel', $channel)
            ->where('status', SmsStatus::Queued)
            ->where(function (Builder $inner): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /** سحبةٌ ماتت: الهاتف أخذها ولم يعد */
    public function scopeStaleClaim(Builder $query): Builder
    {
        return $query
            ->where('status', SmsStatus::Claimed)
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<', now());
    }

    /** في الطابور وقد فات أجلها — تُوسَم ولا تُرسَل */
    public function scopeStale(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [SmsStatus::Queued, SmsStatus::Claimed])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * الشكل الذي يقرؤه سكربت تيرمكس.
     *
     * ثلاثة حقول لا أكثر: `jq` في سطر واحد، و`termux-sms-send` يحتاج
     * رقماً ونصّاً. وكل حقل زائد هنا حقلٌ يمرّ على شبكةٍ ويُكتب في سجلّ
     * الهاتف بلا أن يستعمله أحد.
     *
     * @return array{id: int, to: string, body: string}
     */
    public function toWorkerArray(): array
    {
        return [
            'id' => $this->id,
            'to' => $this->to,
            'body' => (string) $this->body,
        ];
    }
}
