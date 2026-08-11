<?php

use App\Modules\Merchant\Http\Controllers\MerchantController;
use Illuminate\Support\Facades\Route;

/*
| مسارات وحدة التجار — تُحمَّل بـrequire من routes/web.php.
|
| البادئة merchant لا account: مسارات account تخصّ موردَ الإعلان (قائمة،
| تعديل، حذف). وهذه شاشات قراءة عن كل الإعلانات مجتمعة. خلطهما في نفس
| البادئة يجعل «إعلاناتي» و«لوحتي» يبدوان نفس الشيء وهما سؤالان مختلفان.
*/

Route::middleware('auth')->prefix('merchant')->name('merchant.')->group(function (): void {
    Route::get('/', [MerchantController::class, 'dashboard'])->name('dashboard');
    Route::get('/interactions', [MerchantController::class, 'interactions'])->name('interactions');
});
