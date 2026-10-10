<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Listing;

/**
 * ما يُرجعه النشر والتعديل: الإعلان ونتيجة رفع الصور.
 *
 * الصور الفاشلة جزء من النتيجة لا استثناء: الإعلان حُفظ، والمتحكّم هو من
 * يقرّر كيف يُبلّغ بها (`warnings` في JSON، ومفتاح جلسة في الويب).
 */
final readonly class ListingSaveResult
{
    /**
     * @param  array<int, string>  $failed
     */
    public function __construct(
        public Listing $listing,
        public int $imagesStored,
        public array $failed,
    ) {}
}
