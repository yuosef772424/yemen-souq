<?php

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Identity\Models\UserProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

/**
 * صورة الحساب — العمود الذي كان يَعِد ولا يقع.
 *
 * `user_profiles.avatar_path` قائم منذ أول هجرة بلا مسارٍ يكتبه. وهو
 * آخر ما بقي في المشروع من عائلة «السكيمة تَعِد بما لا ينفّذه الكود».
 *
 * ═══ ثلاثة قرارات منسوخة عن `ListingImageService` — وواحد يخالفه ═══
 *
 * WebP، وحذف الأصل، وإزالة EXIF ضمناً بالتحويل: صور الهواتف تحمل موقع
 * التصوير، وصورةٌ شخصية أُخذت في البيت تحمل عنوانه.
 *
 * **والمخالف: قصٌّ مربّع لا تصغيرٌ بنسبة.** الصورة تُعرض في دائرة بحجم
 * ثابت في كل موضع، وصورةٌ أفقية تُصغَّر بنسبتها تظهر بشريطين فارغين أو
 * تُقصّ في المتصفح على هوى كل متصفح. القصّ عند الحفظ مرة واحدة يجعل
 * كل موضع عرضٍ متطابقاً.
 *
 * ═══ ومقاس واحد لا اثنان ═══
 *
 * 256 بكسل. لا مصغّرة ثانية كصور الإعلانات: أكبر عرضٍ للصورة الشخصية في
 * المشروع 96 بكسل، فالمقاس الواحد يخدم الجميع — وملفان بدل واحد يعنيان
 * ضعف التخزين وموضعَين يجب أن يُحذفا معاً.
 */
final class AvatarService
{
    private const DISK = 'public';

    private const SIZE = 256;

    private const QUALITY = 80;

    /** أكبر ملف مقبول قبل المعالجة — بالكيلوبايت */
    public const MAX_KB = 4096;

    /**
     * يستبدل الصورة القائمة ويُرجع المسار الجديد.
     *
     * الحذف بعد الكتابة لا قبلها: لو فشل الترميز بينهما لبقي الحساب بلا
     * صورة وبعمودٍ يشير إلى ملفٍ محذوف — وهو أسوأ من الصورة القديمة.
     */
    public function replace(User $user, UploadedFile $upload): string
    {
        $previous = $user->profile?->avatar_path;

        $path = 'avatars/'.$user->id.'/'.Str::lower(Str::random(16)).'.webp';

        Storage::disk(self::DISK)->put($path, (string) Image::read($upload->getRealPath())
            ->cover(self::SIZE, self::SIZE)
            ->toWebp(self::QUALITY));

        UserProfile::query()
            ->where('user_id', $user->id)
            ->update(['avatar_path' => $path]);

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $path;
    }

    /** الحذف يمسح الملف والعمود معاً — عمودٌ يشير إلى ملفٍ ذهب يكسر كل عرض */
    public function remove(User $user): void
    {
        $path = $user->profile?->avatar_path;

        if ($path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);

        UserProfile::query()
            ->where('user_id', $user->id)
            ->update(['avatar_path' => null]);
    }
}
