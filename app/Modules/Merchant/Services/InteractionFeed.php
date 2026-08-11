<?php

namespace App\Modules\Merchant\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تفاعل الناس مع إعلانات التاجر، في تدفّق واحد.
 *
 * ═══ لماذا ثلاثة استعلامات ودمج في PHP، لا UNION ═══
 *
 * الأشكال الثلاثة مختلفة: السؤال له جواب، والرسالة لها محادثة، والإضافة
 * للمفضلة لا نص لها. UNION يفرض تسوية الأعمدة بحقول فارغة ويمنع استخدام
 * فهرس كل جدول على حدة، ويختلف تركيبه بين المحرّكات. ثلاثة استعلامات
 * محدودة العدد أرخص وأوضح، والدمج على ثلاثين صفاً في الذاكرة بلا كلفة.
 *
 * ═══ ولماذا لا ترقيم صفحات ═══
 *
 * تدفّق مدموج من ثلاثة مصادر لا يمكن ترقيمه صحيحاً بلا UNION: «الصفحة
 * الثانية» تحتاج مؤشراً واحداً على ترتيب لا يوجد في أي جدول. والتاريخ
 * الكامل موجود في صفحته: الأسئلة في صندوق الأسئلة، والرسائل في المحادثات.
 * هذا التدفّق للحاضر لا للأرشيف.
 *
 * ═══ من أضاف إلى المفضلة لا يُكشَف ═══
 *
 * قرار خصوصية لا تفصيل تقني. المشتري الذي يحفظ إعلاناً يتصفّح، ولم يبدأ
 * تعاملاً ولم يوافق على أن يُخبَر البائع باسمه. الوقت والإعلان يكفيان
 * لتقول للتاجر إن هناك اهتماماً؛ والاسم يفتح باباً للتتبّع والملاحقة.
 */
final class InteractionFeed
{
    private const PER_SOURCE = 20;

    public const TOTAL = 30;

    /** @return Collection<int, array<string, mixed>> */
    public function forMerchant(User $user): Collection
    {
        return collect()
            ->concat($this->questions($user))
            ->concat($this->messages($user))
            ->concat($this->favorites($user))
            ->sortByDesc('at')
            ->take(self::TOTAL)
            ->values();
    }

    /** @return array<int, array<string, mixed>> */
    private function questions(User $user): array
    {
        return DB::table('listing_questions')
            ->join('listings', 'listings.id', '=', 'listing_questions.listing_id')
            ->where('listings.user_id', $user->id)
            ->whereNull('listing_questions.hidden_at')
            ->orderByDesc('listing_questions.created_at')
            ->limit(self::PER_SOURCE)
            ->get([
                'listing_questions.id as id',
                'listing_questions.body as body',
                'listing_questions.answered_at as answered_at',
                'listing_questions.created_at as created_at',
                'listings.title as listing_title',
                'listings.slug as listing_slug',
            ])
            ->map(fn (object $row): array => [
                'type' => 'question',
                'at' => Carbon::parse($row->created_at),
                'listing_title' => $row->listing_title,
                'listing_slug' => $row->listing_slug,
                'body' => $row->body,
                'needs_reply' => $row->answered_at === null,
                'id' => (int) $row->id,
            ])
            ->all();
    }

    /**
     * رسائل المشترين وحدها.
     *
     * sender_id != seller_id شرط لازم: بلا استثناء رسائل التاجر نفسه
     * يصير التدفّق يعرض له ما كتبه هو — فيبدو أن هناك تفاعلاً جديداً
     * وليس إلا صدى.
     */
    private function messages(User $user): array
    {
        return DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->join('listings', 'listings.id', '=', 'conversations.listing_id')
            ->where('conversations.seller_id', $user->id)
            ->whereColumn('messages.sender_id', '!=', 'conversations.seller_id')
            ->orderByDesc('messages.created_at')
            ->limit(self::PER_SOURCE)
            ->get([
                'messages.id as id',
                'messages.body as body',
                'messages.read_at as read_at',
                'messages.created_at as created_at',
                'conversations.id as conversation_id',
                'listings.title as listing_title',
                'listings.slug as listing_slug',
            ])
            ->map(fn (object $row): array => [
                'type' => 'message',
                'at' => Carbon::parse($row->created_at),
                'listing_title' => $row->listing_title,
                'listing_slug' => $row->listing_slug,
                'body' => $row->body,
                'needs_reply' => $row->read_at === null,
                'conversation_id' => (int) $row->conversation_id,
                'id' => (int) $row->id,
            ])
            ->all();
    }

    private function favorites(User $user): array
    {
        return DB::table('favorites')
            ->join('listings', 'listings.id', '=', 'favorites.listing_id')
            ->where('listings.user_id', $user->id)
            ->orderByDesc('favorites.created_at')
            ->limit(self::PER_SOURCE)
            // بلا user_id في التحديد — انظر تعليق الصنف
            ->get([
                'favorites.created_at as created_at',
                'listings.title as listing_title',
                'listings.slug as listing_slug',
            ])
            ->map(fn (object $row): array => [
                'type' => 'favorite',
                'at' => Carbon::parse($row->created_at),
                'listing_title' => $row->listing_title,
                'listing_slug' => $row->listing_slug,
                'body' => null,
                'needs_reply' => false,
            ])
            ->all();
    }
}
