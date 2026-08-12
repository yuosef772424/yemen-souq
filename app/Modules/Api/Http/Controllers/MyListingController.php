<?php

namespace App\Modules\Api\Http\Controllers;

use App\Models\User;
use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Api\Http\Resources\ListingDetailResource;
use App\Modules\Api\Http\Resources\ListingResource;
use App\Modules\Api\Support\Pagination;
use App\Modules\Catalog\Http\Requests\StoreListingRequest;
use App\Modules\Catalog\Http\Requests\UpdateListingRequest;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingImage;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Catalog\Services\ListingRenewalService;
use App\Modules\Catalog\Services\ListingSaleMarker;
use App\Modules\Catalog\Services\ListingStatsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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
    private const TABS = ['live', 'sold', 'expired', 'trashed', 'all'];

    public function __construct(
        private readonly ListingImageService $images,
        private readonly ListingRenewalService $renewal,
        private readonly ListingStatsReader $stats,
        private readonly ListingSaleMarker $sales,
        private readonly ListingAttributes $attributes,
        private readonly EventRecorder $events,
    ) {}

    // ─── إعلاناتي ───

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $tab = in_array($request->string('tab')->value(), self::TABS, true)
            ? $request->string('tab')->value()
            : 'live';

        $listings = $this->paginate($user, $tab, Pagination::perPage($request));

        return ListingResource::collection($listings)->additional([
            'meta' => [
                'tab' => $tab,
                'counts' => $this->counts($user),
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
        // الإعلان في معاملة، والصور بعدها: فشل صورة لا يجب أن يُلغي
        // إعلاناً صحيحاً
        $listing = DB::transaction(function () use ($request): Listing {
            $listing = Listing::create([
                ...$request->safe()->except('images', 'attributes'),
                'user_id' => $request->user()->id,
                'sale_type' => 'direct',
                'status' => 'active',
                'published_at' => now(),
                'expires_at' => now()->addDays(30),
            ]);

            // داخل المعاملة مع الإعلان لا بعدها: إعلان سيارة بلا سنة صنع
            // ولا ممشى إعلانٌ ناقص، بخلاف الصور التي يصحّ الإعلان بدونها
            $this->attributes->write($listing, $request->validated('attributes', []));

            return $listing;
        });

        $result = $this->images->attach($listing, $request->file('images', []));

        // بلا هذا الحدث يبقى listings_created في funnel_daily أعمى عن
        // كل ما يُنشر من التطبيق
        $this->events->record(EventName::ListingCreated, 'listing', $listing->id, [
            'category_id' => $listing->category_id,
            'currency' => $listing->currency_code,
            'images' => $result['stored'],
        ]);

        return $this->saved($listing, $result, 'نُشر الإعلان.', 201);
    }

    /**
     * التعديل.
     *
     * ما لا يفعله مقصود بقدر ما يفعله — نفس قائمة `AccountListingController`:
     * لا يغيّر `slug` (رابطٌ منشور في واتساب ومحفوظ في مفضلة زائر)، ولا
     * يمسّ `price_in_base` ولا `search_blob` (يعيد `ListingObserver`
     * حسابها)، ولا يسجّل تغيّر السعر (الـObserver يكتب السجلّ).
     *
     * والمواصفات تُكتب **بعد** الحفظ: `ListingAttributes::write` تقرأ
     * `category_id` من النموذج، فلو سبقت الحفظ لكتبت صفات التصنيف
     * القديم على إعلان صار غيره.
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

        $listing->update($request->safe()->except('images', 'attributes'));

        $this->attributes->write($listing, $request->validated('attributes', []));

        $result = $this->images->attach($listing, $request->file('images', []));

        return $this->saved($listing, $result, 'حُدِّث الإعلان.');
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
     * زرّ واحد بمسارين حسب الحالة في قاعدة البيانات.
     *
     * لماذا لا مساران منفصلان: الزرّ في الواجهة واحد يتبدّل نصّه،
     * ومساران يعنيان أن الواجهة تقرّر أيّهما تستدعي — فتُرسل «بِع» على
     * إعلان مباع إن تأخّر تحديث الشاشة. وفي تطبيقٍ يعمل بلا شبكة أحياناً
     * هذا التأخّر هو الحالة الغالبة لا الاستثناء.
     */
    public function toggleSold(Listing $listing): JsonResponse
    {
        Gate::authorize('markSold', $listing);

        $wasSold = $listing->status === 'sold';

        $done = $wasSold
            ? $this->sales->unmarkSold($listing)
            : $this->sales->markSold($listing);

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

    /**
     * @param  array{stored: int, failed: array<int, string>}  $result
     */
    private function saved(Listing $listing, array $result, string $message, int $status = 200): JsonResponse
    {
        $listing->refresh()->load(['images', 'currency', 'category', 'location', 'user']);

        return response()->json([
            'message' => $message,
            'data' => (new ListingDetailResource($listing, $this->attributes->forDisplay($listing)))
                ->resolve(),
            'images_stored' => $result['stored'],
            // الصور الفاشلة تُبلَّغ ولا تُفشل الطلب: الإعلان نُشر، وإخفاء
            // ما سقط يجعل المعلن يظن أن صورَه الثماني كلها هناك
            'warnings' => array_values($result['failed']),
        ], $status);
    }

    /**
     * العدّادات في استعلامين لا خمسة — نفس حساب لوحة الويب.
     *
     * و«المنتهية» من `expires_at` لا من `status = 'expired'`: لا شيء في
     * النظام يكتب تلك الحالة (وهو مقصود — انظر `scopeNotExpired`)،
     * فتبويب يعتمد عليها يبقى صفراً بينما الإعلانات تنتهي فعلاً.
     *
     * @return array{live: int, sold: int, expired: int, trashed: int, all: int}
     */
    private function counts(User $user): array
    {
        $now = now()->toDateTimeString();

        $row = Listing::query()
            ->ownedBy($user)
            ->selectRaw('COUNT(*) as all_count')
            ->selectRaw(
                "SUM(CASE WHEN status = 'active' AND (expires_at IS NULL OR expires_at >= ?) THEN 1 ELSE 0 END) as live_count",
                [$now]
            )
            ->selectRaw(
                'SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < ? THEN 1 ELSE 0 END) as expired_count',
                [$now]
            )
            ->selectRaw("SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_count")
            ->first();

        return [
            'live' => (int) ($row->live_count ?? 0),
            'sold' => (int) ($row->sold_count ?? 0),
            'expired' => (int) ($row->expired_count ?? 0),
            'trashed' => Listing::onlyTrashed()->ownedBy($user)->count(),
            'all' => (int) ($row->all_count ?? 0),
        ];
    }

    private function paginate(User $user, string $tab, int $perPage)
    {
        $query = Listing::query()
            ->ownedBy($user)
            ->with(['images', 'currency', 'category', 'location']);

        match ($tab) {
            'live' => $query->active()->notExpired(),
            'sold' => $query->where('status', 'sold'),
            'expired' => $query->expired(),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };

        return $query
            ->orderByDesc($tab === 'trashed' ? 'deleted_at' : 'created_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
