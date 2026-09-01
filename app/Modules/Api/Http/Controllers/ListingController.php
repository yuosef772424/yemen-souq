<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Api\Http\Resources\ListingDetailResource;
use App\Modules\Api\Http\Resources\ListingResource;
use App\Modules\Api\Support\Pagination;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Engagement\Services\ConversationService;
use App\Modules\Engagement\Services\FavoriteToggler;
use App\Modules\Engagement\Services\QuestionService;
use App\Modules\Search\DTO\SearchCriteria;
use App\Modules\Search\Pagination\BoundedPaginator;
use App\Modules\Search\Services\SearchQueryBuilder;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Services\ArabicNormalizer;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * التصفّح والبحث وصفحة الإعلان — عامّة بلا رمز.
 *
 * ═══ البحث هو الفهرس ═══
 *
 * لا `GET /listings` بمعنى «كل الإعلانات» ثم `GET /search` بجانبه:
 * القائمة بلا معايير هي نفسها بحثٌ بمعايير فارغة، ومساران يعنيان
 * محرّكَي استعلامٍ يتفرّقان. و`SearchCriteria::fromRequest` تقرأ نفس
 * المعاملات التي يقرؤها الويب حرفياً — فرابط بحثٍ في المتصفح يُنسخ
 * إلى التطبيق ويعمل.
 *
 * ═══ match_mode يُعاد للعميل ═══
 *
 * المطابقة على درجتين: صارمة تشترط كل مجموعة كلمات، ثم متراجعة تكفيها
 * واحدة. والثانية تُنتج نتائج **ليست** ما طلبه الباحث تماماً — فإخفاء
 * ذلك عن التطبيق يجعله يعرض «نتائج البحث» على ما هو «الأقرب». الحقل
 * هو ما يسمح بعنوان مختلف فوق القائمة.
 *
 * ═══ ولماذا تُسجَّل الأحداث هنا أيضاً ═══
 *
 * لو سجّلها الويب وحده لانقسمت التحليلات: نسبة النقر والاستعلامات
 * بصفر نتائج تُحسب على جزء من المستخدمين وتُقرأ كأنها على كلهم. وهو
 * نفس عطل «رقم يبدو صحيحاً وليس كذلك» الذي يتكرّر في هذا المشروع.
 */
class ListingController
{
    public function __construct(
        private readonly SearchQueryBuilder $search,
        private readonly EventRecorder $events,
        private readonly ExchangeRateService $rates,
        private readonly ListingAttributes $attributes,
        private readonly FavoriteToggler $favorites,
        private readonly QuestionService $questions,
        private readonly ConversationService $conversations,
        private readonly ArabicNormalizer $normalizer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $criteria = $this->criteria($request);

        $startedAt = microtime(true);
        $result = $this->search->search($criteria);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        /** @var LengthAwarePaginator $listings */
        $listings = $result['listings'];

        $this->recordSearch($criteria, $listings->total(), $durationMs, $result['mode']);
        $this->events->recordImpressions(
            $listings->pluck('id')->all(),
            ($listings->firstItem() ?? 1) - 1,
        );

        return ListingResource::collection($listings)->additional([
            'meta' => [
                'match_mode' => $result['mode'],
                // meta.total مسقوف — والعميل يحتاج معرفة ذلك ليعرض «+»
                // بدل رقمٍ يقرؤه كنهائي. انظر BoundedPaginator
                'results_capped' => $listings instanceof BoundedPaginator && $listings->isCapped(),
                'query' => $criteria->query,
                'filters' => $criteria->activeFilterKeys(),
            ],
        ]);
    }

    /**
     * صفحة الإعلان.
     *
     * ═══ المالك يُعرف بالرمز، وغيابه لا يمنع القراءة ═══
     *
     * المسار عام: الزائر بلا حساب يرى الإعلان. و`$request->user()` تعود
     * null هناك — فالمالك لا يُقارَن بشيء، والعدّاد يزيد. وهو الصحيح:
     * زائرٌ مجهول مشاهدةٌ حقيقية.
     *
     * وزيارة المالك لا تُحسب، بنفس حجّة الويب: المعلن يفتح إعلانه ليرى
     * المشاهدات ثم يحدّث الصفحة، فيصير الرقم الذي جاء يقرأه من صنعه.
     */
    public function show(Request $request, Listing $listing): JsonResponse
    {
        $user = $request->user();
        $isOwner = $user !== null && $user->id === $listing->user_id;

        // المالك يرى إعلانه في كل حالة — وإلا فمعاينة ما عُدِّل تقود إلى 404
        abort_unless($listing->status === 'active' || $isOwner, 404);

        $listing->load(['currency', 'category.parent', 'location.parent', 'images', 'user']);

        if (! $isOwner) {
            // زيادة بلا لمس updated_at — وإلا بدا كل إعلان مُعدَّلاً للتو
            $listing->newQuery()->whereKey($listing->getKey())->increment('views_count');

            $this->events->record(EventName::ListingViewed, 'listing', $listing->id, [
                'source' => $request->string('from')->value() ?: 'api',
                'category_id' => $listing->category_id,
                'currency' => $listing->currency_code,
            ]);
        }

        $resource = new ListingDetailResource(
            $listing,
            $this->attributes->forDisplay($listing),
            $this->equivalents($listing),
        );

        return response()->json([
            'data' => $resource->resolve($request),

            /**
             * قدرات المستخدم على هذا الإعلان — محسوبة في الخادم.
             *
             * كل واحدة منها شرطٌ مركّب (ملكية، حالة إعلان، انتهاء،
             * حساب). واشتقاقها في التطبيق يعني نسخ الشروط في كل عميل،
             * وأول اختلاف يُظهر زرّاً يُرفض بعد الضغط.
             */
            'abilities' => [
                'is_owner' => $isOwner,
                'can_favorite' => $user !== null,
                'is_favorited' => $user !== null && $this->favorites->has($user, $listing),
                'can_ask' => $user !== null && $this->questions->canAsk($user, $listing),
                'can_message' => $user !== null && $this->conversations->canStart($user, $listing),
                'can_reveal_contact' => ! $listing->hide_phone && $listing->contact_phone !== null,
            ],
        ]);
    }

    /**
     * كشف رقم التواصل — مسار مستقل بعدّاد.
     *
     * نفس قرار `ContactRevealController` في الويب: الرقم لا يُرسَل مع
     * الإعلان. كشفه أقوى إشارة نية شراء في المنصة — أقوى من المشاهدة
     * وأقوى من الإضافة للمفضلة — وإرساله ضمن الصفحة يُلغي قياسه ويفتح
     * حصاده آلياً بزحفٍ على نقطة نهاية عامة.
     */
    public function contact(Listing $listing): JsonResponse
    {
        abort_unless($listing->status === 'active', 404);
        abort_if($listing->hide_phone || $listing->contact_phone === null, 404);

        $listing->newQuery()->whereKey($listing->getKey())->increment('contacts_count');

        $this->events->record(EventName::ContactRevealed, 'listing', $listing->id, [
            'category_id' => $listing->category_id,
            'method' => 'phone',
        ]);

        return response()->json(['phone' => $listing->contact_phone]);
    }

    /** إعلانات قريبة في القيمة الموحّدة — لا في الرقم المكتوب */
    public function similar(Listing $listing): AnonymousResourceCollection
    {
        abort_unless($listing->status === 'active', 404);

        $query = Listing::query()
            ->published()
            ->whereKeyNot($listing->getKey())
            ->where('category_id', $listing->category_id)
            ->with(['currency', 'category', 'location', 'images'])
            ->limit(6);

        if ($listing->price_in_base !== null) {
            $query->orderByRaw('ABS(price_in_base - ?)', [(float) $listing->price_in_base]);
        } else {
            $query->latest('published_at');
        }

        return ListingResource::collection($query->get());
    }

    // ─── الداخل ───

    /**
     * المعايير من الطلب، بحجم صفحة محكوم بسقف الـAPI.
     *
     * `SearchCriteria::fromRequest` تقرأ `per_page` بسقفها هي (60).
     * والـAPI أضيق (50) لأن الاستجابة هنا تحمل الصور والتصنيفات
     * والمواقع كاملة لكل صف — بينما القالب يعرض ما يعرض. فالسقف يُعاد
     * فرضه ببناء المعايير من جديد لا بتعديل الـDTO: ذلك عقدٌ يخصّ
     * البحث، وهذا حدٌّ يخصّ النقل.
     */
    private function criteria(Request $request): SearchCriteria
    {
        $base = SearchCriteria::fromRequest($request);

        return new SearchCriteria(
            query: $base->query,
            categoryId: $base->categoryId,
            locationId: $base->locationId,
            priceMin: $base->priceMin,
            priceMax: $base->priceMax,
            priceCurrency: $base->priceCurrency,
            listingCurrency: $base->listingCurrency,
            condition: $base->condition,
            withImageOnly: $base->withImageOnly,
            sort: $base->sort,
            perPage: Pagination::perPage($request),
            attributes: $base->attributes,
        );
    }

    /**
     * السعر بكل العملات النشطة.
     *
     * بالسعر التاريخي (`rate_date`) لا بسعر اليوم: إعلان نُشر قبل شهر
     * يحتفظ بتحويله الصحيح وقتها — وهو فارق جوهري في سوق بعملة متقلبة.
     *
     * @return list<array<string, mixed>>
     */
    private function equivalents(Listing $listing): array
    {
        if ($listing->price_amount === null || $listing->currency_code === null) {
            return [];
        }

        $result = [];

        foreach (Currency::active()->get() as $currency) {
            if ($currency->code === $listing->currency_code) {
                continue;
            }

            $amount = $this->rates->convert(
                (float) $listing->price_amount,
                $listing->currency_code,
                $currency->code,
                $listing->rate_date?->toDateString(),
            );

            $result[] = [
                'currency' => $currency->code,
                'amount' => $amount === null ? null : (float) $amount,
                'formatted' => $currency->format($amount),
            ];
        }

        return $result;
    }

    /**
     * حدث البحث — بنفس شرط الويب.
     *
     * تصفّح الصفحة الأولى بلا فلاتر ليس بحثاً، وتسجيله يُفسد كل إحصاءات
     * «الاستعلامات بصفر نتائج».
     */
    private function recordSearch(SearchCriteria $criteria, int $total, int $durationMs, string $mode): void
    {
        if (! $criteria->hasQuery() && ! $criteria->hasPriceFilter()) {
            return;
        }

        $this->events->record(EventName::SearchPerformed, payload: [
            // النص المطبّع فقط: التجميع يعمل صحيحاً، والأصل لا يُحفظ
            'q_normalized' => $this->normalizer->normalize($criteria->query),
            'results' => $total,
            'duration_ms' => $durationMs,
            'match_mode' => $mode,
            'filters' => $criteria->activeFilterKeys(),
            'price_currency' => $criteria->hasPriceFilter() ? $criteria->priceCurrency : null,
            'sort' => $criteria->sort,
        ]);
    }
}
