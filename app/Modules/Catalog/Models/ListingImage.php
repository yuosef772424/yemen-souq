<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ListingImage extends Model
{
    protected $table = 'listing_images';

    public $timestamps = false;

    protected $fillable = [
        'listing_id', 'path', 'thumb_path',
        'width', 'height', 'size_bytes',
        'is_primary', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * رابط الصورة — الموضع الوحيد الذي يُبنى فيه.
     *
     * ═══ لماذا لا `asset('storage/'.$path)` ═══
     *
     * تلك الصيغة تفترض شيئين: أن الملف على قرص هذا الخادم، وأنه يُخدَم من
     * رابط رمزي تحت `public/`. والافتراضان يسقطان معاً بمجرد الانتقال إلى
     * تخزين كائني — فتُكتب الصور في S3 وتُطلب من نطاقنا، وتعود 404.
     *
     * وكانت الصيغة مكرّرة في ثمانية قوالب: بطاقة النتائج، والمفضلة،
     * والمحادثات، وصفحة الإعلان، ولوحة الحساب. أي أن تبديل التخزين كان
     * يتطلّب تعديل ثمانية ملفات — ونسيان واحدٍ منها يُنتج صورةً مكسورة في
     * صفحة لا يفتحها المطوّر كثيراً.
     *
     * و`Storage::disk(...)->url()` يعرف كيف يبني الرابط لكل سائق: رابطنا
     * الرمزي محلياً، ونطاق الحاوية أو CDN مع S3.
     */
    public function url(): string
    {
        return $this->urlFor($this->path);
    }

    /**
     * رابط المصغّرة، ويقع إلى الأصل حين لا توجد.
     *
     * الوقوع مقصود لا احتياطي: `thumb_path` يقبل NULL في المخطط، والصور
     * المزروعة قديماً بلا مصغّرات. وعرض الأصل أثقل قليلاً — وأفضل بكثير
     * من مربّع فارغ.
     */
    public function thumbUrl(): string
    {
        return $this->urlFor($this->thumb_path ?? $this->path);
    }

    private function urlFor(string $path): string
    {
        return Storage::disk(config('filesystems.media', 'public'))->url($path);
    }
}
