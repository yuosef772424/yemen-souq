<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\ListingResource;
use App\Modules\Api\Support\Pagination;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Services\FavoriteToggler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * المفضلة.
 *
 * ═══ فعلان صريحان لا تبديل واحد ═══
 *
 * الويب يستعمل `toggle` لأن الزرّ فيه واحد والصفحة تُعاد كاملةً بعد
 * الضغط. والتطبيق يختلف: الضغطة تُرسَل وقد تُعاد المحاولة على شبكة
 * متقطّعة — و«بدِّل» المُعاد مرتين يعود إلى نقطة البداية بلا أن يعرف
 * أحد. `PUT` يعني «ليكن مفضّلاً» و`DELETE` يعني «ليكن غير مفضّل»،
 * وكلاهما مُتماثل مهما تكرّر.
 *
 * والخدمة تبقى `FavoriteToggler` نفسها: العدّاد الذرّي وحدث التحليلات
 * وحماية `insertOrIgnore` كلها فيها، ونسخُها هنا يعني عدّاداً ينحرف.
 *
 * ═══ والقائمة تعرض المحذوف والمنتهي ═══
 *
 * نفس قرار الويب: إخفاء ما انتهى يجعل المستخدم يظن أنه فقد ما حفظه بلا
 * تفسير. الوسم — `is_expired` و`status` في المورد — هو الجواب.
 */
class FavoriteController
{
    public function __construct(
        private readonly FavoriteToggler $favorites,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /**
         * الترتيب بتاريخ *الحفظ* لا بتاريخ الإعلان — ولهذا الربط بدل
         * whereIn. القائمة ذاكرةُ المستخدم: ما حفظه قبل دقيقة يجب أن
         * يكون أولاً ولو كان الإعلان من العام الماضي.
         */
        $listings = Listing::query()
            ->withTrashed()
            ->join('favorites', 'favorites.listing_id', '=', 'listings.id')
            ->where('favorites.user_id', $request->user()->id)
            ->select('listings.*', 'favorites.created_at as favorited_at')
            ->with(['images', 'currency', 'category', 'location'])
            ->orderByDesc('favorites.created_at')
            ->paginate(Pagination::perPage($request));

        return ListingResource::collection($listings);
    }

    /** ليكن مفضّلاً — ولو كان كذلك أصلاً */
    public function store(Request $request, Listing $listing): JsonResponse
    {
        $user = $request->user();

        if (! $this->favorites->has($user, $listing)) {
            $this->favorites->toggle($user, $listing);
        }

        return response()->json([
            'message' => 'أُضيف إلى المفضلة.',
            'is_favorited' => true,
        ]);
    }

    /** ليكن غير مفضّل — ولو لم يكن كذلك أصلاً */
    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        $user = $request->user();

        if ($this->favorites->has($user, $listing)) {
            $this->favorites->toggle($user, $listing);
        }

        return response()->json([
            'message' => 'أُزيل من المفضلة.',
            'is_favorited' => false,
        ]);
    }
}
