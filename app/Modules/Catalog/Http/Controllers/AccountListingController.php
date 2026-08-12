<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Models\User;
use App\Modules\Catalog\Http\Requests\UpdateListingRequest;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingImage;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Catalog\Services\ListingRenewalService;
use App\Modules\Catalog\Services\ListingSaleMarker;
use App\Modules\Catalog\Services\ListingStatsReader;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * لوحة المعلن: إعلاناتي · تعديل · حذف · تجديد · مشاهدات.
 *
 * لماذا متحكّم واحد بسبع طرق لا سبعة متحكّمات __invoke: كلها أفعال على
 * موردٍ واحد بنفس قاعدة الملكية وبنفس التبعيات الثلاث. توزيعها على
 * سبعة ملفات يكرّر الاعتماديات سبع مرات ويجعل مراجعة الصلاحيات تتنقّل
 * بين ملفات — وهذا بالضبط ما نحاول تجنّبه بوضع القاعدة في Policy.
 *
 * وكل طريقة تبدأ بـGate::authorize. لا استثناء ولا اختصار: القراءة
 * السريعة لهذا الملف يجب أن تُظهر سبعة فحوص لسبعة أفعال.
 */
class AccountListingController
{
    /**
     * التبويبات: ما يمكن للنظام أن ينتجه فعلاً، لا كل قيم عمود status.
     *
     * و«مباعة» تبويب لا حالة مخفية: الإعلان المباع يخرج من «المعروضة»
     * ومن نتائج البحث معاً، فبلا تبويب له يختفي من كل مكان — ولا يجد
     * المعلن طريقاً للتراجع عن نقرة خاطئة.
     */
    private const TABS = ['live', 'sold', 'expired', 'trashed', 'all'];

    public function __construct(
        private readonly ListingImageService $images,
        private readonly ListingRenewalService $renewal,
        private readonly ListingStatsReader $stats,
        private readonly ListingSaleMarker $sales,
        private readonly ListingAttributes $attributes,
    ) {}

    // ─── إعلاناتي ───

    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = in_array($request->string('tab')->value(), self::TABS, true)
            ? $request->string('tab')->value()
            : 'live';

        return view('catalog::account.index', [
            'tab' => $tab,
            'counts' => $this->counts($user),
            'listings' => $this->paginate($user, $tab),
            'baseCurrency' => Currency::base(),
            'renewal' => $this->renewal,
        ]);
    }

    // ─── تعديل ───

    public function edit(Listing $listing): View
    {
        Gate::authorize('update', $listing);

        $listing->load('images');

        return view('catalog::account.edit', [
            'listing' => $listing,
            'currencies' => Currency::active()->get(),
            'categories' => Category::active()->roots()->with('children')->get(),
            'governorates' => Location::active()->governorates()->with('children')->get(),
            'maxImages' => ListingImageService::MAX_PER_LISTING,
            'remainingImages' => max(0, ListingImageService::MAX_PER_LISTING - $listing->images->count()),

            // نفس مصدرَي نموذج النشر: خريطة الصفات لكل تصنيف، والقيم
            // الحالية. القالب الجزئي واحد للنموذجين، فاختلاف ما يُمرَّر
            // إليه هو كل ما يفرّق بين «أضف» و«عدّل»
            'attributesByCategory' => $this->attributes->groupedByCategory(),
            'selectedAttributes' => $this->attributes->currentFor($listing),
        ]);
    }

    /**
     * الحفظ.
     *
     * ما لا يفعله هذا الفعل مقصود بقدر ما يفعله:
     *
     *  · لا يغيّر slug عند تغيير العنوان. الرابط قد يكون منشوراً في
     *    واتساب ومحفوظاً في مفضلة زائر؛ تغييره يكسر ما لا نراه.
     *    (ListingObserver::fillSlug يملأ slug عند الفراغ فقط، فالسلوك
     *    قائم أصلاً — وهذا تنبيه لمن يعدّله لاحقاً.)
     *
     *  · لا يمسّ price_in_base ولا search_blob ولا title_normalized.
     *    ListingObserver يعيد حسابها كلها في saving. ولو حسبناها هنا
     *    لتفرّق الحساب في موضعين.
     *
     *  · لا يسجّل تغيّر السعر: الـObserver يكتب listing_price_history
     *    في updated. فتعديل السعر من اللوحة يُسجَّل تلقائياً.
     *
     * والمواصفات تُكتب **بعد** حفظ الإعلان لا قبله: ListingAttributes::write
     * تقرأ category_id من النموذج لتعرف أي صفات مسموحة. فلو سبقت الحفظ
     * لقرأت التصنيف القديم — فتكتب صفات «سيارات» على إعلان صار «شققاً»،
     * وهي بالضبط الحالة التي جاء التعديل ليدعمها.
     */
    public function update(UpdateListingRequest $request, Listing $listing): RedirectResponse
    {
        Gate::authorize('update', $listing);

        $listing->update($request->safe()->except('images', 'attributes'));

        $this->attributes->write($listing, $request->validated('attributes', []));

        $result = $this->images->attach($listing, $request->file('images', []));

        return redirect()
            ->route('account.listings.edit', $listing)
            ->with('status', $result['stored'] > 0
                ? "حُدِّث الإعلان وأُضيفت {$result['stored']} صورة."
                : 'حُدِّث الإعلان.')
            ->with('warnings', $result['failed']);
    }

    public function destroyImage(Listing $listing, ListingImage $image): RedirectResponse
    {
        Gate::authorize('update', $listing);

        $this->images->delete($image);

        return redirect()
            ->route('account.listings.edit', $listing)
            ->with('status', 'حُذفت الصورة.');
    }

    // ─── حذف ───

    /**
     * حذف منطقي لا نهائي.
     *
     * البديل المرفوض: forceDelete. القيود في المخطط تسلسلية
     * (cascadeOnDelete) على listing_images وlisting_daily_stats
     * وlisting_price_history — فحذف نهائي يمحو معه سجل المشاهدات
     * وتاريخ السعر. أي: الضغطة الواحدة تُتلف البيانات التي بُني عليها
     * المشروع كله، وبلا رجعة.
     *
     * والملفات على القرص تبقى: حذف قابل للتراجع مع ملفات محذوفة ليس
     * قابلاً للتراجع فعلاً — يُستعاد الصف بلا صور. تنظيف المهملات
     * القديمة عمل أمرٍ مجدول لاحقاً، لا عمل زر يضغطه مستخدم.
     */
    public function destroy(Listing $listing): RedirectResponse
    {
        Gate::authorize('delete', $listing);

        $listing->delete();

        return redirect()
            ->route('account.listings', ['tab' => 'trashed'])
            ->with('status', 'حُذف الإعلان. يمكنك استعادته من تبويب «المحذوفة».');
    }

    /**
     * الاستعادة.
     *
     * أُضيفت لأن حذفاً منطقياً بلا استعادة أسوأ من حذف نهائي: الصف باقٍ
     * في القاعدة والمعلن لا يملك أي طريق إليه — يظن أنه فُقد، وهو موجود.
     */
    public function restore(Listing $listing): RedirectResponse
    {
        Gate::authorize('restore', $listing);

        $listing->restore();

        return redirect()
            ->route('account.listings')
            ->with('status', 'استُعيد الإعلان.');
    }

    // ─── تجديد ───

    public function renew(Listing $listing): RedirectResponse
    {
        Gate::authorize('renew', $listing);

        $outcome = $this->renewal->renew($listing);

        return redirect()
            ->route('account.listings', ['tab' => $outcome->succeeded() ? 'live' : 'expired'])
            ->with($outcome->succeeded() ? 'status' : 'error', $outcome->message());
    }

    // ─── البيع ───

    /**
     * زرّ واحد بمسارين حسب الحالة الحالية.
     *
     * لماذا لا مساران منفصلان: الزرّ في الواجهة واحد يتبدّل نصّه، ومساران
     * يعنيان أن الواجهة تقرّر أيّهما تستدعي — فتُرسل «بِع» على إعلان مباع
     * إن تأخّر تحديث الصفحة. القرار هنا مبنيّ على الحالة في قاعدة
     * البيانات لا على ما رآه المتصفح.
     */
    public function toggleSold(Listing $listing): RedirectResponse
    {
        Gate::authorize('markSold', $listing);

        if ($listing->status === 'sold') {
            $done = $this->sales->unmarkSold($listing);

            return redirect()
                ->route('account.listings')
                ->with(
                    $done ? 'status' : 'error',
                    $done ? 'أُعيد الإعلان إلى العرض.' : 'تعذّر إرجاع الإعلان.'
                );
        }

        $done = $this->sales->markSold($listing);

        return redirect()
            ->route('account.listings', ['tab' => $done ? 'sold' : null])
            ->with(
                $done ? 'status' : 'error',
                $done
                    ? 'عُلّم الإعلان مباعاً واختفى من نتائج البحث. يمكنك التراجع من هنا.'
                    : 'لا يمكن تعليم هذا الإعلان مباعاً في حالته الحالية.'
            );
    }

    // ─── مشاهدات ───

    public function stats(Listing $listing): View
    {
        Gate::authorize('viewStats', $listing);

        return view('catalog::account.stats', [
            'listing' => $listing,
            'stats' => $this->stats->forListing($listing),
            'reader' => $this->stats,
        ]);
    }

    // ─── التفاصيل ───

    /**
     * العدّادات في استعلامين لا خمسة.
     *
     * استعلام لكل تبويب يعني خمس رحلات إلى القاعدة لرسم أربعة أرقام في
     * أعلى الصفحة. والاستعلام الثاني ضروري لا زائد: الحذف المنطقي يُقصي
     * الصفوف المحذوفة من الاستعلام الأول بأثر SoftDeletes، فلا يمكن
     * عدّها فيه.
     *
     * ولاحظ أن «المنتهية» تُحسب من expires_at لا من status = 'expired':
     * لا شيء في النظام يكتب تلك الحالة (وهذا مقصود — انظر scopeNotExpired)،
     * فتبويب يعتمد عليها يبقى صفراً إلى الأبد بينما الإعلانات تنتهي فعلاً.
     *
     * @return array{live: int, expired: int, trashed: int, all: int}
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

    private function paginate(User $user, string $tab): LengthAwarePaginator
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
            ->paginate(12)
            ->withQueryString();
    }
}
