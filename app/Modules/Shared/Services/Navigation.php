<?php

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * عناصر التنقّل الثابتة في الترويسة: التصنيفات الجذرية والمحافظات.
 *
 * **لماذا خدمة بتخزين مؤقت لا استعلام في القالب:** الترويسة تظهر في كل صفحة
 * من الموقع. استعلامان (تصنيفات + محافظات) في كل طلب لكل زائر هو بالضبط
 * التكلفة التي رُفض لأجلها عدّاد الإشعارات غير المقروء في نفس الترويسة —
 * والفرق أن هذين لا يتغيّران إلا حين يعدّلهما مشرف، فيصلحان للتخزين تماماً.
 *
 * التخزين دائم لا موقوت: الإبطال يقع عند الكتابة (انظر `Category::booted`
 * و`Location::booted`)، ومدّةٌ موقوتة هنا تعني إمّا تعديلاً إدارياً لا يظهر
 * أو استعلاماً يتكرّر بلا سبب.
 */
class Navigation
{
    public const CATEGORIES_KEY = 'nav.categories';

    public const GOVERNORATES_KEY = 'nav.governorates';

    /** التصنيفات الجذرية بأبنائها — لقائمة «كل الأقسام» وبطاقات التصفّح */
    public function categories(): Collection
    {
        return Cache::rememberForever(self::CATEGORIES_KEY, fn (): Collection => Category::active()
            ->roots()
            ->with(['children' => fn ($query) => $query->where('is_active', true)])
            ->get(['id', 'name_ar', 'slug', 'icon', 'parent_id', 'sort_order']));
    }

    public function governorates(): Collection
    {
        return Cache::rememberForever(self::GOVERNORATES_KEY, fn (): Collection => Location::active()
            ->governorates()
            ->get(['id', 'name_ar', 'slug']));
    }

    /**
     * الاختصارات المعروضة نصّاً في شريط الترويسة.
     *
     * أربعة فقط: الشريط يُقرأ في لمحة، وقائمةٌ من تسعة اسمٍ تتحوّل إلى جدار
     * نصّي يُتخطّى كله. البقية خلف «كل الأقسام». والأربعة ليست عشوائية —
     * هي ما تضعه منصات الإعلانات المبوّبة في شريطها العلوي لأنها أكثر
     * الأقسام طلباً: سيارات، عقارات، وظائف، خدمات.
     */
    public function shortcuts(): Collection
    {
        $preferred = ['vehicles', 'real-estate', 'jobs', 'services'];

        $categories = $this->categories()->keyBy('slug');

        return collect($preferred)
            ->map(fn (string $slug) => $categories->get($slug))
            ->filter()
            ->values();
    }

    public static function flush(): void
    {
        Cache::forget(self::CATEGORIES_KEY);
        Cache::forget(self::GOVERNORATES_KEY);
    }
}
