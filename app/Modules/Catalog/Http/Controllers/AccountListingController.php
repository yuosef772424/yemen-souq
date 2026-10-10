<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Actions\ToggleListingSold;
use App\Modules\Catalog\Actions\UpdateListing;
use App\Modules\Catalog\Http\Requests\UpdateListingRequest;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingImage;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Catalog\Services\ListingRenewalService;
use App\Modules\Catalog\Services\ListingStatsReader;
use App\Modules\Catalog\Services\OwnerListings;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
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
    /** حجم صفحة الويب — الـAPI يأخذ حجمه من العميل */
    private const PER_PAGE = 12;

    public function __construct(
        private readonly ListingImageService $images,
        private readonly ListingRenewalService $renewal,
        private readonly ListingStatsReader $stats,
        private readonly ListingAttributes $attributes,
        private readonly OwnerListings $owned,
        private readonly UpdateListing $updateListing,
        private readonly ToggleListingSold $toggleSale,
    ) {}

    // ─── إعلاناتي ───

    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = $this->owned->resolveTab($request->string('tab')->value());

        return view('catalog::account.index', [
            'tab' => $tab,
            'counts' => $this->owned->counts($user),
            'listings' => $this->owned->paginate($user, $tab, self::PER_PAGE),
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
            // نفس تقييد نموذج النشر — والفرع الحالي للإعلان يبقى معروضاً
            // حتى لو عُطِّل بعد النشر، وإلا فتح التعديل وفقد صاحبه فرعه
            // الأصلي بلا أن يغيّره هو.
            //
            // شرط الأب (parent_id) الذي يضيفه التحميل الشغوف يسبق هذا
            // الإغلاق، وorWhere بلا تجميع كان سيهرب من ذلك القيد تماماً —
            // فيُظهر فرعاً من تصنيف أبٍ آخر لمجرّد تطابق المعرّف. التجميع
            // بقوس داخلي (where(fn) بدل orWhere مباشرة) هو ما يمنع ذلك.
            'categories' => Category::active()->roots()
                ->with(['children' => fn ($q) => $q->where(
                    fn ($q2) => $q2->active()->orWhere('id', $listing->category_id)
                )])
                ->get(),
            'governorates' => Location::active()->governorates()
                ->with(['children' => fn ($q) => $q->where(
                    fn ($q2) => $q2->active()->orWhere('id', $listing->location_id)
                )])
                ->get(),
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
     * ما لا يفعله هذا الفعل وترتيب خطواته (حقول ثم مواصفات ثم صور) موثّقان
     * في `UpdateListing`، وهو ما يستدعيه متحكّم الـAPI أيضاً. وهنا ما يخصّ
     * الويب وحده: رسالة الجلسة ووجهة إعادة التوجيه.
     */
    public function update(UpdateListingRequest $request, Listing $listing): RedirectResponse
    {
        Gate::authorize('update', $listing);

        $result = ($this->updateListing)(
            $listing,
            $request->safe()->except('images', 'attributes'),
            $request->validated('attributes', []),
            $request->file('images', []),
        );

        return redirect()
            ->route('account.listings.edit', $listing)
            ->with('status', $result->imagesStored > 0
                ? "حُدِّث الإعلان وأُضيفت {$result->imagesStored} صورة."
                : 'حُدِّث الإعلان.')
            ->with('warnings', $result->failed);
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
     * زرّ واحد بمسارين حسب الحالة الحالية — القرار في `ToggleListingSold`،
     * وهنا الرسائل ووجهة التحويل فقط.
     */
    public function toggleSold(Listing $listing): RedirectResponse
    {
        Gate::authorize('markSold', $listing);

        ['wasSold' => $wasSold, 'done' => $done] = ($this->toggleSale)($listing);

        if ($wasSold) {
            return redirect()
                ->route('account.listings')
                ->with(
                    $done ? 'status' : 'error',
                    $done ? 'أُعيد الإعلان إلى العرض.' : 'تعذّر إرجاع الإعلان.'
                );
        }

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
}
