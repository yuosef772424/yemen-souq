<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * إضافة الإعلان إلى المفضلة وإزالته، مع العدّاد.
 *
 * ═══ لماذا insertOrIgnore ثم فحص عدد الصفوف المتأثرة ═══
 *
 * العدّاد listings.favorites_count ذاكرة محسوبة، ومصدر الحقيقة جدول
 * favorites. والخطأ الشائع هنا: «أدرج ثم زد العدّاد». ضغطتان متلاحقتان
 * على الزر (أو نقرة مزدوجة على اتصال بطيء) تعطيان إدراجاً واحداً —
 * يمنعه المفتاح المركّب — وزيادتين للعدّاد. فيصبح العدّاد أكبر من عدد
 * الصفوف، ولا شيء يكشف ذلك لاحقاً.
 *
 * insertOrIgnore يُرجع عدد الصفوف المُدرجة فعلاً. العدّاد يزيد إن كان
 * واحداً، ولا يزيد إن كان صفراً. المعاملة تجعل الاثنين ذرّة واحدة.
 *
 * ═══ ولماذا عدّاد أصلاً ═══
 *
 * لأن بطاقة الإعلان في نتائج البحث تعرضه، وحساب COUNT لكل بطاقة في كل
 * صفحة نتائج يعني 24 استعلاماً إضافياً. العدّاد يُقرأ مع الصف نفسه.
 *
 * ═══ ولماذا يُسجَّل حدث أيضاً ═══
 *
 * العدّاد يقول «كم»، ولا يقول «متى». وعمود listing_daily_stats.favorites
 * كان يُكتب صفراً ثابتاً لأن لا حدث يُسجَّل — فلوحة المعلن تعرض صفر
 * حفظات لإعلان محفوظ فعلاً. الحدث هو الفرق بين رقم وتاريخ.
 *
 * والحدث يُسجَّل داخل المعاملة بعد نجاح الكتابة لا قبلها: EventRecorder
 * يخزّن في الذاكرة ويكتب في نهاية الطلب، فتسجيل حدث لإدراج فشل يعني
 * حدثاً بلا صف — وهو أسوأ من صف بلا حدث، لأن التجميع يعتمد عليه.
 */
final class FavoriteToggler
{
    public function __construct(
        private readonly EventRecorder $events,
    ) {}

    /**
     * @return bool صار مفضّلاً (true) أو أُزيل (false)
     */
    public function toggle(User $user, Listing $listing): bool
    {
        return DB::transaction(function () use ($user, $listing): bool {
            $inserted = DB::table('favorites')->insertOrIgnore([
                'user_id' => $user->id,
                'listing_id' => $listing->id,
                'created_at' => now(),
            ]);

            if ($inserted === 1) {
                $this->adjust($listing, 1);

                $this->events->record(EventName::FavoriteAdded, 'listing', $listing->id);

                return true;
            }

            $deleted = DB::table('favorites')
                ->where('user_id', $user->id)
                ->where('listing_id', $listing->id)
                ->delete();

            if ($deleted === 1) {
                $this->adjust($listing, -1);

                $this->events->record(EventName::FavoriteRemoved, 'listing', $listing->id);
            }

            return false;
        });
    }

    public function has(User $user, Listing $listing): bool
    {
        return DB::table('favorites')
            ->where('user_id', $user->id)
            ->where('listing_id', $listing->id)
            ->exists();
    }

    /**
     * أيّ إعلانات هذه الصفحة محفوظة عند هذا المستخدم — باستعلام واحد.
     *
     * `has` فرديّ، ونداؤه لكل بطاقة في صفحة نتائج هو نفس الـ24 استعلاماً
     * التي وُجد `favorites_count` أصلاً لتفاديها. وبلا هذه الدالة يبقى
     * أمام صفحة النتائج خياران كلاهما سيئ: قلبٌ لا يعرف حالته فيبدو
     * معطَّلاً بعد الضغط، أو قراءةُ جدول `favorites` من داخل وحدة أخرى.
     *
     * @param  array<int, int>  $listingIds
     * @return array<int, int> معرّفات المحفوظ منها
     */
    public function among(User $user, array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        return DB::table('favorites')
            ->where('user_id', $user->id)
            ->whereIn('listing_id', $listingIds)
            ->pluck('listing_id')
            ->all();
    }

    /**
     * العدّاد لا ينزل تحت الصفر.
     *
     * unsignedInteger في المخطط، فقيمة سالبة خطأ SQL يُسقط الطلب. وحدوثه
     * ممكن: صف أُدرج قبل وجود هذه الخدمة، أو عدّاد أُعيد ضبطه يدوياً.
     * الحدّ عند الصفر يجعل العطل انحرافاً في رقم لا صفحة خطأ.
     */
    private function adjust(Listing $listing, int $delta): void
    {
        $query = Listing::withTrashed()->whereKey($listing->getKey());

        if ($delta > 0) {
            $query->increment('favorites_count', $delta);

            return;
        }

        $query->where('favorites_count', '>', 0)->decrement('favorites_count', abs($delta));
    }
}
