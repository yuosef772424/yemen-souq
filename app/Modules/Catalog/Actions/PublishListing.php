<?php

namespace App\Modules\Catalog\Actions;

use App\Models\User;
use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingAttributes;
use App\Modules\Catalog\Services\ListingImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * نشر إعلان جديد: الصف والمواصفات في معاملة، ثم الصور، ثم الحدث.
 *
 * الصلاحية (بوابة الهاتف المُوثَّق) تسبق هذا الفعل في الطبقة التي تستدعيه؛
 * وهو لا يقرأ الطلب: يستقبل حقولاً صُفّيت أصلاً بـ`safe()`.
 */
final class PublishListing
{
    /** مدّة العرض الأولى بالأيام */
    public const LIFETIME_DAYS = 30;

    public function __construct(
        private readonly ListingAttributes $attributes,
        private readonly ListingImageService $images,
        private readonly EventRecorder $events,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  حقول الإعلان بلا `images` و`attributes`
     * @param  array<int|string, mixed>  $attributes  مواصفات التصنيف
     * @param  array<int, UploadedFile>  $uploads
     */
    public function __invoke(User $owner, array $fields, array $attributes, array $uploads): ListingSaveResult
    {
        // الإعلان في معاملة، والصور بعدها: فشل صورة لا يجب أن يُلغي
        // إعلاناً صحيحاً
        $listing = DB::transaction(function () use ($owner, $fields, $attributes): Listing {
            $listing = Listing::create([
                ...$fields,
                'user_id' => $owner->id,
                'status' => 'active',
                'published_at' => now(),
                'expires_at' => now()->addDays(self::LIFETIME_DAYS),
            ]);

            // داخل المعاملة مع الإعلان لا بعدها: إعلان سيارة بلا سنة صنع
            // ولا ممشى إعلانٌ ناقص، بخلاف الصور التي يصحّ الإعلان بدونها
            $this->attributes->write($listing, $attributes);

            return $listing;
        });

        $result = $this->images->attach($listing, $uploads);

        // بلا هذا الحدث يبقى listings_created في funnel_daily أعمى عن
        // كل ما يُنشر من التطبيق
        $this->events->record(EventName::ListingCreated, 'listing', $listing->id, [
            'category_id' => $listing->category_id,
            'currency' => $listing->currency_code,
            'images' => $result['stored'],
        ]);

        return new ListingSaveResult($listing, $result['stored'], $result['failed']);
    }
}
