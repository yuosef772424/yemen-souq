<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\ModerationReasonRequest;
use App\Modules\Admin\Services\ListingModerator;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * مراجعة الإعلانات.
 *
 * التبويبات تعبّر عن عمل لا عن قيم عمود status. «غير مراجَعة» ليست حالة
 * في المخطط بل شرط على reviewed_at، و«مُبلَّغ عنها» تأتي من جدول آخر.
 * تبويب لكل قيمة status ينتج تبويبات فارغة أبداً (draft, pending لا
 * يكتبهما شيء في النظام) — وتبويب فارغ دائماً يعلّم المشرف أن يتجاهل
 * التبويبات.
 *
 * والبحث يستخدم title_normalized لا title: «هاتف» و«هاتف» بألف مهموزة
 * ليسا نصّاً واحداً في MySQL، والعمود المعيَّر موجود أصلاً لهذا السبب.
 */
class ListingModerationController
{
    private const TABS = ['queue', 'reported', 'rejected', 'featured', 'trashed', 'all'];

    public function __construct(
        private readonly ListingModerator $moderator,
        private readonly ArabicNormalizer $normalizer,
    ) {}

    public function index(Request $request): View
    {
        $tab = in_array($request->string('tab')->value(), self::TABS, true)
            ? $request->string('tab')->value()
            : 'queue';

        return view('admin::listings.index', [
            'tab' => $tab,
            'listings' => $this->paginate($tab, $request->string('q')->value()),
            'q' => $request->string('q')->value(),
            'counts' => $this->counts(),
        ]);
    }

    public function approve(Request $request, Listing $listing): RedirectResponse
    {
        $this->moderator->approve($listing, $request->user());

        return back()->with('status', 'اعتُمد الإعلان.');
    }

    public function reject(ModerationReasonRequest $request, Listing $listing): RedirectResponse
    {
        $this->moderator->reject($listing, $request->user(), $request->reason());

        return back()->with('status', 'رُفض الإعلان وسُجِّل السبب.');
    }

    public function pause(Request $request, Listing $listing): RedirectResponse
    {
        $this->moderator->pause($listing, $request->user(), $request->input('reason'));

        return back()->with('status', 'أُوقف الإعلان.');
    }

    public function feature(Request $request, Listing $listing): RedirectResponse
    {
        $this->moderator->feature($listing, $request->user(), (int) $request->input('days', ListingModerator::FEATURE_DAYS));

        return back()->with('status', 'مُيِّز الإعلان.');
    }

    public function unfeature(Request $request, Listing $listing): RedirectResponse
    {
        $this->moderator->unfeature($listing, $request->user());

        return back()->with('status', 'أُلغي التمييز.');
    }

    public function destroy(Request $request, Listing $listing): RedirectResponse
    {
        $this->moderator->delete($listing, $request->user(), $request->input('reason'));

        return back()->with('status', 'حُذف الإعلان — قابل للاستعادة من تبويب «المحذوفة».');
    }

    public function restore(Request $request, Listing $listing): RedirectResponse
    {
        $this->moderator->restore($listing, $request->user());

        return back()->with('status', 'استُعيد الإعلان.');
    }

    /**
     * لماذا استعلام فرعي على listing_reports لا علاقة reports() على
     * الموديل: إضافة العلاقة تجعل وحدة الكتالوج تعرف بجدول تملكه وحدة
     * التفاعل، فتصير الوحدتان غير قابلتين للفصل من أجل تبويب واحد في
     * لوحة التحكم. الاعتماد يتجه إلى الأعلى دائماً: الإدارة تعرف
     * الوحدتين، ولا تعرف إحداهما الأخرى.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'queue' => Listing::query()->whereNull('reviewed_at')->count(),
            'reported' => Listing::query()->whereIn('id', $this->reportedIds())->count(),
            'rejected' => Listing::query()->where('status', 'rejected')->count(),
            'featured' => Listing::query()->where('is_featured', true)->count(),
            'trashed' => Listing::onlyTrashed()->count(),
            'all' => Listing::query()->count(),
        ];
    }

    private function paginate(string $tab, ?string $q): LengthAwarePaginator
    {
        $query = Listing::query()->with(['user', 'category', 'location', 'currency']);

        match ($tab) {
            'queue' => $query->whereNull('reviewed_at')->orderBy('created_at'),
            'reported' => $query->whereIn('id', $this->reportedIds())
                ->addSelect(['open_reports_count' => DB::table('listing_reports')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('listing_id', 'listings.id')
                    ->whereIn('status', ['open', 'reviewing']), ])
                ->orderByDesc('open_reports_count'),
            'rejected' => $query->where('status', 'rejected')->orderByDesc('reviewed_at'),
            'featured' => $query->where('is_featured', true)->orderByDesc('featured_until'),
            'trashed' => $query->onlyTrashed()->orderByDesc('deleted_at'),
            default => $query->orderByDesc('created_at'),
        };

        // الطابور الأقدم أولاً بقصد: الأحدث أولاً يترك أقدم إعلان بلا
        // مراجعة إلى الأبد كلما زاد الوارد على المُنجَز
        if ($q !== null && $q !== '') {
            $needle = $this->normalizer->normalize($q);
            $query->where(fn (Builder $inner) => $inner
                ->where('title_normalized', 'like', "%{$needle}%")
                ->orWhere('id', ctype_digit($q) ? (int) $q : 0));
        }

        return $query->paginate(20)->withQueryString();
    }

    /** معرّفات الإعلانات التي عليها بلاغ مفتوح */
    private function reportedIds(): \Illuminate\Database\Query\Builder
    {
        return DB::table('listing_reports')
            ->select('listing_id')
            ->whereIn('status', ['open', 'reviewing']);
    }
}
