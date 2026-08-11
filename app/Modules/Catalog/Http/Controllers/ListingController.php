<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Engagement\Services\ConversationService;
use App\Modules\Engagement\Services\FavoriteToggler;
use App\Modules\Engagement\Services\QuestionService;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * صفحة الإعلان.
 *
 * تعتمد على ثلاث خدمات من وحدة Engagement. والاتجاه صحيح: Engagement
 * يعتمد على Catalog (المحادثة تخصّ إعلاناً)، وCatalog يستهلك خدماتها في
 * طبقة العرض وحدها — لا في الموديل ولا في الـObserver. لو استوردت
 * Listing أو ListingObserver شيئاً من Engagement لصار الاعتماد دائرياً،
 * وذلك هو الخط الذي لا يُعبَر.
 */
class ListingController
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly EventRecorder $events,
        private readonly FavoriteToggler $favorites,
        private readonly QuestionService $questions,
        private readonly ConversationService $conversations,
        private readonly ListingAttributes $attributes,
    ) {}

    public function show(Request $request, Listing $listing): View
    {
        $user = $request->user();
        $isOwner = $user?->id === $listing->user_id;

        // المالك يرى إعلانه في كل حالة — وإلا فمعاينة ما عُدِّل تقود إلى 404
        abort_unless($listing->status === 'active' || $isOwner, 404);

        $listing->load(['currency', 'category.parent', 'location.parent', 'images', 'user']);

        /**
         * زيارة المالك لإعلانه لا تُحسب — لا في العدّاد ولا في الأحداث.
         *
         * المعلن يفتح إعلانه ليتأكد أنه ظهر، ثم ليرى المشاهدات، ثم يحدّث
         * الصفحة. فيصبح الرقم الذي جاء ليقرأه من صنعه هو. وأسوأ من رقم
         * مفقود رقمٌ يبدو صحيحاً وليس كذلك.
         */
        if (! $isOwner) {
            // زيادة بلا لمس updated_at — وإلا بدا كل إعلان مُعدَّلاً للتو
            $listing->newQuery()->whereKey($listing->getKey())->increment('views_count');

            $this->recordVisit($request, $listing);
        }

        return view('catalog::show', [
            'listing' => $listing,
            'baseCurrency' => Currency::base(),
            'equivalents' => $this->equivalents($listing),
            'similar' => $this->similar($listing),
            'isOwner' => $isOwner,
            'attributes' => $this->attributes->forDisplay($listing),

            // التفاعل: كل قرار عرض محسوب في الخادم لا في القالب —
            // زرّ يظهر ثم يُرفض بعد الضغط أسوأ من زرّ لا يظهر
            'questions' => $this->questions->forListing($listing),
            'isFavorited' => $user !== null && $this->favorites->has($user, $listing),
            'canAsk' => $user !== null && $this->questions->canAsk($user, $listing),
            'canMessage' => $user !== null && $this->conversations->canStart($user, $listing),
        ]);
    }

    /**
     * حدث المشاهدة، وحدث النقر إن جاء من نتائج البحث.
     *
     * لماذا هنا لا بـJavaScript في صفحة النتائج: الرابط يحمل from وpos
     * وq، فالنقرة تُسجَّل بمجرد وصول الزائر — ولا تُفقد إن كان JS معطّلاً.
     */
    private function recordVisit(Request $request, Listing $listing): void
    {
        $source = $request->string('from')->value() ?: 'direct';

        $this->events->record(EventName::ListingViewed, 'listing', $listing->id, [
            'source' => $source,
            'category_id' => $listing->category_id,
            'currency' => $listing->currency_code,
        ]);

        if ($source !== 'search') {
            return;
        }

        $this->events->record(EventName::SearchResultClicked, 'listing', $listing->id, [
            'q_normalized' => $request->string('q')->value() ?: null,
            'position' => $request->integer('pos') ?: null,
        ]);
    }

    /**
     * السعر مُعبَّراً عنه بكل العملات النشطة — المشكلة الأصلية معكوسة:
     * بدل أن يخمّن المشتري قيمة رقم بعملة لا يفكر بها، نعرضها بعملته.
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

            $result[] = [
                'currency' => $currency,
                'amount' => $this->rates->convert(
                    (float) $listing->price_amount,
                    $listing->currency_code,
                    $currency->code,
                    $listing->rate_date?->toDateString(),
                ),
            ];
        }

        return $result;
    }

    /** إعلانات قريبة في القيمة الموحّدة — لا في الرقم المكتوب */
    private function similar(Listing $listing): iterable
    {
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

        return $query->get();
    }
}
