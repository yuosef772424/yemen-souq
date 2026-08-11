<?php

use App\Modules\Merchant\Http\Controllers\MerchantController;
use Illuminate\Support\Facades\Route;

/*
| مسارات وحدة التجار — يكتشفها ModuleServiceProvider تلقائياً.
|
| البادئة merchant لا account: مسارات account تخصّ موردَ الإعلان (قائمة،
| تعديل، حذف). وهذه شاشات قراءة عن كل الإعلانات مجتمعة. خلطهما في نفس
| البادئة يجعل «إعلاناتي» و«لوحتي» يبدوان نفس الشيء وهما سؤالان مختلفان.
|
| ولا هجرة في هذه الوحدة، وهذا مقصود: كل ما تحتاجه اللوحة موجود —
| listing_daily_stats وfavorites وlisting_questions وmessages. الوحدة
| التي تُقرأ ولا تكتب لا تملك جداول؛ وإضافة جدول «merchant_stats» يعني
| نسخة ثالثة من نفس الأرقام تتفرّق عن أصلها عند أول تعديل.
|
| ولا موفّر خدمة كذلك: لم يكن يفعل غير تسجيل القوالب، وذلك يجري تلقائياً.
*/

Route::middleware('auth')->prefix('merchant')->name('merchant.')->group(function (): void {
    Route::get('/', [MerchantController::class, 'dashboard'])->name('dashboard');
    Route::get('/interactions', [MerchantController::class, 'interactions'])->name('interactions');
});
