<?php

use App\Modules\Engagement\Http\Controllers\ListingReportController;
use Illuminate\Support\Facades\Route;

/*
| مسار الإبلاغ — ملف منفصل بقصد.
|
| بقية مسارات وحدة التفاعل معرَّفة في routes/web.php. إضافة هذا المسار
| هناك تعني تعديلاً في وسط ملف قائم؛ وملف صغير مستقل يُضاف بسطر require
| واحد في نهايته. المقايضة: مسارات الوحدة موزَّعة على موضعين — يُجمعان
| في ملف واحد للوحدة عند أول إعادة تنظيم.
*/

Route::middleware('auth')->group(function (): void {
    Route::post('/listings/{listing}/report', [ListingReportController::class, 'store'])
        ->name('listing.report');
});
