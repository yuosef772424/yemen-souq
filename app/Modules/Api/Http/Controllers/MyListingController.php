<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\ListingDetailResource;
use App\Modules\Api\Http\Resources\ListingResource;
use App\Modules\Api\Support\Pagination;
use App\Modules\Catalog\Actions\ListingSaveResult;
use App\Modules\Catalog\Actions\PublishListing;
use App\Modules\Catalog\Actions\ToggleListingSold;
use App\Modules\Catalog\Actions\UpdateListing;
use App\Modules\Catalog\Http\Requests\StoreListingRequest;
use App\Modules\Catalog\Http\Requests\UpdateListingRequest;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingImage;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Catalog\Services\ListingRenewalService;
use App\Modules\Catalog\Services\ListingStatsReader;
use App\Modules\Catalog\Services\OwnerListings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * لوحة المعلن: إعلاناتي · نشر · تعديل · حذف · استعادة · تجديد · بيع ·
 * مشاهدات.
 *
 * ═══ كل طريقة تبدأ بـGate::authorize ═══
 *
 * نفس قاعدة `AccountListingController` حرفياً، ولنفس السبب: القاعدة في
 * `ListingPolicy` تُخترَق مرة واحدة أو لا تُخترَق. ونسيانها في سطر واحد
 * يعني ثغرة IDOR كاملة — `PATCH /api/v1/me/listings/{slug}` لإعلان شخص
 * آخر.
 *
 * والقراءة السريعة لهذا الملف يجب أن تُظهر فحصاً لكل فعل.
 *
 * ═══ الطلبات مُعاد استعمالها كما هي ═══
 *
 * `StoreListingRequest` و`UpdateListingRequest` تُستدعيان هنا بلا
 * اشتقاق. قواعدهما مشتقّة من قاعدة البيانات (`ListingAttributes::rules`)
 * ورسائلهما عربية، و`ValidationException` تُصيَّر JSON تلقائياً حين
 * يطلب الطالب JSON — وهو ما يضمنه `ForceJsonResponse`.
 *
 * ونسخُهما لأجل الـAPI كان سيعني قاعدتَي تحقّق للنشر: واحدة تمنع
 * `status` من المرور وأخرى تنساه.
 *
 * ═══ الصور بـmultipart ═══
 *
 * لا base64 في JSON. صورة بحجم ميغابايتين تصير 2.7 بعد الترميز، وتُحمَّل
 * في ذاكرة الخادم كاملةً قبل أن تُقرأ — على اتصال يمني هذا فارقٌ ملموس
 * لمن يرفع ثماني صور.
 */
class MyListingController
{
    public function __construct(
        private readonly ListingImageService $images,
        private readonly ListingRenewalService $renewal,
        private readonly ListingStatsReader $stats,
        private readonly ListingAttributes $attributes,
        private readonly OwnerListings $owned,
        private readonly PublishListing $publish,
        private readonly UpdateListing $updateListing,
        private readonly ToggleListingSold $toggleSale,
    ) {}

    // ─── إعلاناتي ───

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $tab = $this->owned->resolveTab($request->string('tab')->value());

        $listings = $this->owned->paginate($user, $tab, Pagination::perPage($request));

        return ListingResource::collection($listings)->additional([
            'meta' => [
                'tab' => $tab,
                'counts' => $this->owned->counts($user),
            ],
        ]);
    }

    /**
     * إعلاني كما أراه أنا — بما فيه غير المنشور.
     *
     * منفصل عن `ListingController::show` لأن ذاك يشترط `active` لغير
     * المالك ويزيد العدّاد ويسجّل حدثاً. ومسارٌ واحد بشرطٍ على الملكية
     * كان سيخلط «مشاهدة» بـ«مراجعة صاحبها» في نفس العدّاد.
     */
    public function show(Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $listing->load(['currency', 'category', 'location', 'images', 'user']);

        return response()->json([
            'data' => (new ListingDetailResource($listing, $this->attributes->forDisplay($listing)))
                ->resolve(),
            'raw_attributes' => $this->attributes->currentFor($listing),
            'can_renew' => $this->renewal->canRenew($listing),
        ]);
    }

    // ─── النشر ───

    public function store(StoreListingRequest $request): JsonResponse
    {
        // المعاملة والمواصفات والصور والحدث كلها في `PublishListing`
        $result = ($this->publish)(
            $request->user(),
            $request->safe()->except('images', 'attributes'),
            $request->validated('attributes', []),
            $request->file('images', []),
        );

        return $this->saved($result, 'نُشر الإعلان.', 201);
    }

    /**
     * التعديل.
     *
     * ما لا يفعله وترتيب خطواته (حقول ثم مواصفات ثم صور) موثّقان في
     * `UpdateListing`، وهو ما يستدعيه الويب أيضاً.
     *
     * ═══ ولماذا POST لا PATCH ═══
     *
     * الدلالة تقول PATCH. لكنّ **PHP لا يحلّل جسد multipart إلا على
     * POST**: يملأ `$_FILES` من طلب POST وحده، فطلبُ PATCH يحمل صوراً
     * يصل إلى `$request->file('images')` فارغاً — بلا خطأ، والإعلان
     * يُحدَّث وصوره تختفي في الطريق.
     *
     * والحيلة المعتادة (`_method=PATCH` في الجسد) تُصلح التوجيه ولا
     * تُصلح شيئاً للعميل: هو يرسل POST على أي حال. فالمسار يقول ما يقع
     * فعلاً بدل أن يَعِد بفعلٍ لا يُستعمل.
     */
    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $result = ($this->updateListing)(
            $listing,
            $request->safe()->except('images', 'attributes'),
            $request->validated('attributes', []),
            $request->file('images', []),
        );

        return $this->saved($result, 'حُدِّث الإعلان.');
    }

    public function destroyImage(Listing $listing, ListingImage $image): JsonResponse
    {
        Gate::authorize('update', $listing);

        $this->images->delete($image);

        return response()->json(['message' => 'حُذفت الصورة.']);
    }

    // ─── الحذف والاستعادة ───

    /**
     * حذف منطقي لا نهائي — والسبب في `AccountListingController`:
     * القيود التسلسلية تمحو معه سجلّ المشاهدات وتاريخ السعر بلا رجعة.
     */
    public function destroy(Listing $listing): JsonResponse
    {
        Gate::authorize('delete', $listing);

        $listing->delete();

        return response()->json([
            'message' => 'حُذف الإعلان. يمكنك استعادته من تبويب «المحذوفة».',
        ]);
    }

    public function restore(Listing $listing): JsonResponse
    {
        Gate::authorize('restore', $listing);

        $listing->restore();

        return response()->json([
            'message' => 'استُعيد الإعلان.',
            'data' => new ListingResource($listing->fresh()->load(['images', 'currency', 'category', 'location'])),
        ]);
    }

    // ─── التجديد ───

    /**
     * النتيجة تحمل رمزاً لا رسالة وحدها.
     *
     * `RenewalOutcome` ثلاث حالات — جُدِّد، مبكّر، غير قابل — ولكلٍّ
     * سلوكٌ مختلف في التطبيق (نجاح، عدّاد تنازلي، تعطيل الزرّ). ومطابقة
     * نصّ عربي للتفريق بينها تنكسر بأول تحسين في الصياغة.
     */
    public function renew(Listing $listing): JsonResponse
    {
        Gate::authorize('renew', $listing);

        $outcome = $this->renewal->renew($listing);

        return response()->json([
            'message' => $outcome->message(),
            'code' => $outcome->value,
            'data' => new ListingResource($listing->fresh()->load(['images', 'currency', 'category', 'location'])),
        ], $outcome->succeeded() ? 200 : 422);
    }

    // ─── البيع ───

    /**
     * زرّ واحد بمسارين حسب الحالة في قاعدة البيانات — القرار في
     * `ToggleListingSold`، وهنا الرسائل ورمز الرفض فقط.
     */
    public function toggleSold(Listing $listing): JsonResponse
    {
        Gate::authorize('markSold', $listing);

        ['wasSold' => $wasSold, 'done' => $done] = ($this->toggleSale)($listing);

        if (! $done) {
            return response()->json([
                'message' => $wasSold
                    ? 'تعذّر إرجاع الإعلان.'
                    : 'لا يمكن تعليم هذا الإعلان مباعاً في حالته الحالية.',
                'code' => 'sale_toggle_rejected',
            ], 422);
        }

        return response()->json([
            'message' => $wasSold
                ? 'أُعيد الإعلان إلى العرض.'
                : 'عُلّم الإعلان مباعاً واختفى من نتائج البحث.',
            'data' => new ListingResource($listing->fresh()->load(['images', 'currency', 'category', 'location'])),
        ]);
    }

    // ─── مشاهدات ───

    public function stats(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('viewStats', $listing);

        $days = (int) $request->integer('days', 30);

        return response()->json([
            'data' => $this->stats->forListing($listing, max(7, min($days, 90))),
        ]);
    }

    // ─── الداخل ───

    private function saved(ListingSaveResult $result, string $message, int $status = 200): JsonResponse
    {
        $listing = $result->listing->refresh()->load(['images', 'currency', 'category', 'location', 'user']);

        return response()->json([
            'message' => $message,
            'data' => (new ListingDetailResource($listing, $this->attributes->forDisplay($listing)))
                ->resolve(),
            'images_stored' => $result->imagesStored,
            // الصور الفاشلة تُبلَّغ ولا تُفشل الطلب: الإعلان نُشر، وإخفاء
            // ما سقط يجعل المعلن يظن أن صورَه الثماني كلها هناك
            'warnings' => array_values($result->failed),
        ], $status);
    }
}
