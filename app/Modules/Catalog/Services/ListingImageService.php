<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;
use Throwable;

/**
 * معالجة صور الإعلانات: تصغير، تحويل، صورة مصغّرة، تخزين، حذف.
 *
 * قرارات مقصودة:
 *
 * 1. كل صورة تُحوَّل إلى WebP. الحجم أصغر 25-35% من JPEG بنفس الجودة،
 *    وهذا فارق ملموس لمستخدم على اتصال بطيء.
 *
 * 2. التحويل يُزيل بيانات EXIF ضمناً — ومنها إحداثيات GPS. صور الهواتف
 *    تحمل موقع التصوير، ونشرها مع إعلان بيت أو سيارة تسريب خصوصية حقيقي.
 *
 * 3. الملف الأصلي لا يُحفظ إطلاقاً. لا نحتاجه، وحفظه يضاعف التخزين
 *    ويُبقي بيانات EXIF على القرص.
 *
 * 4. فشل صورة واحدة لا يُفشل الإعلان. الصور ثانوية؛ إعلان بلا صورة
 *    أفضل من إعلان لم يُنشر.
 */
final class ListingImageService
{
    public const MAX_PER_LISTING = 8;

    private const MAX_WIDTH = 1600;

    private const THUMB_WIDTH = 400;

    private const QUALITY = 82;

    private const THUMB_QUALITY = 70;

    private const DISK = 'public';

    /**
     * @param  array<int, UploadedFile>  $uploads
     * @return array{stored: int, failed: array<int, string>}
     */
    public function attach(Listing $listing, array $uploads): array
    {
        $existing = $listing->images()->count();
        $room = max(0, self::MAX_PER_LISTING - $existing);

        $failed = [];
        $stored = 0;

        foreach (array_slice(array_values($uploads), 0, $room) as $upload) {
            if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
                $failed[] = 'ملف غير صالح تم تجاهله.';

                continue;
            }

            try {
                $this->store($listing, $upload, $existing + $stored);
                $stored++;
            } catch (Throwable $exception) {
                // الملف قد يكون تالفاً أو بصيغة لا يقرأها المحرك
                Log::warning('فشل معالجة صورة إعلان', [
                    'listing_id' => $listing->id,
                    'name' => $upload->getClientOriginalName(),
                    'error' => $exception->getMessage(),
                ]);

                $failed[] = "تعذّر معالجة الصورة: {$upload->getClientOriginalName()}";
            }
        }

        if (count($uploads) > $room) {
            $failed[] = 'تم قبول '.self::MAX_PER_LISTING.' صور كحد أقصى للإعلان.';
        }

        $this->ensureSinglePrimary($listing);

        return ['stored' => $stored, 'failed' => $failed];
    }

    private function store(Listing $listing, UploadedFile $upload, int $sortOrder): ListingImage
    {
        $directory = "listings/{$listing->id}";
        $name = Str::lower(Str::random(16));

        Storage::disk(self::DISK)->makeDirectory($directory);

        // القراءة تُصحّح الاتجاه تلقائياً من EXIF (autoOrientation في config/image.php)
        $full = Image::read($upload->getRealPath())->scaleDown(width: self::MAX_WIDTH);

        $width = $full->width();
        $height = $full->height();

        $path = "{$directory}/{$name}.webp";
        $encoded = (string) $full->toWebp(self::QUALITY);
        Storage::disk(self::DISK)->put($path, $encoded);

        // قراءة ثانية للمصغّرة: كائن Intervention قابل للتغيير،
        // فالتصغير الأول عدّل النسخة الأصلية في الذاكرة
        $thumbPath = "{$directory}/{$name}-thumb.webp";
        Storage::disk(self::DISK)->put(
            $thumbPath,
            (string) Image::read($upload->getRealPath())
                ->scaleDown(width: self::THUMB_WIDTH)
                ->toWebp(self::THUMB_QUALITY)
        );

        return ListingImage::create([
            'listing_id' => $listing->id,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'width' => $width,
            'height' => $height,
            'size_bytes' => strlen($encoded),
            'is_primary' => $sortOrder === 0,
            'sort_order' => $sortOrder,
            'created_at' => now(),
        ]);
    }

    /** حذف صورة: الصف والملفان معاً، ثم ترقية بديلة إن كانت الأساسية */
    public function delete(ListingImage $image): void
    {
        $listing = $image->listing;

        Storage::disk(self::DISK)->delete(array_filter([$image->path, $image->thumb_path]));

        $image->delete();

        if ($listing !== null) {
            $this->ensureSinglePrimary($listing);
        }
    }

    /** حذف كل صور إعلان — يُستدعى قبل حذف الإعلان نهائياً */
    public function deleteAll(Listing $listing): void
    {
        Storage::disk(self::DISK)->deleteDirectory("listings/{$listing->id}");

        $listing->images()->delete();
    }

    /**
     * إعادة الترتيب حسب قائمة معرّفات.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(Listing $listing, array $orderedIds): void
    {
        $images = $listing->images()->get()->keyBy('id');
        $position = 0;

        foreach ($orderedIds as $id) {
            $image = $images->get((int) $id);

            if ($image === null) {
                continue;
            }

            $image->update([
                'sort_order' => $position,
                'is_primary' => $position === 0,
            ]);

            $position++;
        }
    }

    /** صورة أساسية واحدة بالضبط — لا صفر ولا اثنتان */
    private function ensureSinglePrimary(Listing $listing): void
    {
        $images = $listing->images()->orderBy('sort_order')->orderBy('id')->get();

        if ($images->isEmpty()) {
            return;
        }

        $primary = $images->firstWhere('is_primary', true) ?? $images->first();

        foreach ($images as $image) {
            $shouldBePrimary = $image->id === $primary->id;

            if ($image->is_primary !== $shouldBePrimary) {
                $image->update(['is_primary' => $shouldBePrimary]);
            }
        }
    }
}
