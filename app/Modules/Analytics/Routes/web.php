<?php

use App\Modules\Analytics\Http\Controllers\PrivacyController;
use Illuminate\Support\Facades\Route;

/*
| صفحة الخصوصية — يكتشفها ModuleServiceProvider تلقائياً.
|
| تحت `account/` لتجاور بقية لوحة المستخدم، وفي وحدة التحليلات لأنها
| تملك الجدول: حذف الوحدة يأخذ الصفحة معها، ورابطها في إعدادات الحساب
| مشروطٌ بـ`Route::has('account.privacy')` فيختفي وحده.
*/

Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('/privacy', [PrivacyController::class, 'edit'])->name('privacy');
    Route::put('/privacy', [PrivacyController::class, 'update'])->name('privacy.update');
});
