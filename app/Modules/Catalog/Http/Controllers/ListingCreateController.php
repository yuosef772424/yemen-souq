<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Http\Requests\StoreListingRequest;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Identity\Support\DemoUser;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ListingCreateController
{
    public function __construct(
        private readonly ListingImageService $images,
        private readonly EventRecorder $events,
        private readonly ListingAttributes $attributes,
    ) {}

    public function create(): View
    {
        return view('catalog::create', [
            'currencies' => Currency::active()->get(),
            'categories' => Category::active()->roots()->with('children')->get(),
            'governorates' => Location::active()->governorates()->with('children')->get(),
            'maxImages' => ListingImageService::MAX_PER_LISTING,
            'attributesByCategory' => $this->attributes->groupedByCategory(),
        ]);
    }

    public function store(StoreListingRequest $request): RedirectResponse
    {
        // الإعلان في معاملة، والصور بعدها:
        // فشل صورة لا يجب أن يُلغي إعلاناً صحيحاً
        $listing = DB::transaction(function () use ($request): Listing {
            $listing = Listing::create([
                ...$request->safe()->except('images', 'attributes'),
                'user_id' => DemoUser::current()->id,
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

        // بلا هذا الحدث يبقى listings_created صفراً دائماً
        // في funnel_daily، فيبدو مسار التحويل ناقصاً
        $this->events->record(EventName::ListingCreated, 'listing', $listing->id, [
            'category_id' => $listing->category_id,
            'currency' => $listing->currency_code,
            'images' => $result['stored'],
        ]);

        return redirect()
            ->route('listing.show', $listing)
            ->with('status', $this->statusMessage($result))
            ->with('warnings', $result['failed']);
    }

    private function statusMessage(array $result): string
    {
        if ($result['stored'] === 0) {
            return 'تم نشر إعلانك بلا صور.';
        }

        return "تم نشر إعلانك مع {$result['stored']} صورة.";
    }
}
