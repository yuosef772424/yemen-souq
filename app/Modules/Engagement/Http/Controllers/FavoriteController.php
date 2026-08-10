<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Services\FavoriteToggler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FavoriteController
{
    public function __construct(
        private readonly FavoriteToggler $favorites,
    ) {
    }

    /**
     * التبديل يعود إلى حيث جاء المستخدم.
     *
     * back() لا redirect ثابت: الزرّ يُضغط من صفحة الإعلان ومن نتائج البحث
     * ومن صفحة المفضلة نفسها. إعادةٌ ثابتة تُخرج المستخدم من تصفّحه —
     * وهو يريد إضافة إعلان ومتابعة النظر لا الانتقال.
     */
    public function toggle(Request $request, Listing $listing): RedirectResponse
    {
        $added = $this->favorites->toggle($request->user(), $listing);

        return back()->with('status', $added ? 'أُضيف إلى المفضلة.' : 'أُزيل من المفضلة.');
    }

    /**
     * المفضلة تعرض المحذوف والمنتهي أيضاً — بوسم واضح.
     *
     * إخفاء الإعلان الذي انتهى يجعل المستخدم يظن أنه فقد ما حفظه بلا
     * تفسير. وإظهاره كأنه قائم يهدر رحلة اتصال. الوسم هو الجواب.
     */
    public function index(Request $request): View
    {
        /**
         * الترتيب بتاريخ *الحفظ* لا بتاريخ الإعلان.
         *
         * وهذا سبب الربط بدل whereIn: القائمة ذاكرةُ المستخدم، وما حفظه
         * قبل دقيقة يجب أن يكون أولاً — ولو كان الإعلان نفسه من العام
         * الماضي. ترتيب بتاريخ الإعلان يجعل ما حفظه للتو يظهر في الصفحة
         * الثالثة، فيظن أن الحفظ لم ينجح.
         *
         * والربط لا يكرّر الصفوف: مفتاح favorites مركّب (user_id,
         * listing_id) والاستعلام يثبّت المستخدم — فصف واحد لكل إعلان.
         */
        $listings = Listing::query()
            ->withTrashed()
            ->join('favorites', 'favorites.listing_id', '=', 'listings.id')
            ->where('favorites.user_id', $request->user()->id)
            ->select('listings.*', 'favorites.created_at as favorited_at')
            ->with(['images', 'currency', 'category', 'location'])
            ->orderByDesc('favorites.created_at')
            ->paginate(12);

        return view('engagement::favorites', ['listings' => $listings]);
    }
}