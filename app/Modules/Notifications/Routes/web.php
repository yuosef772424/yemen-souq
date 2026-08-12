<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| الإشعارات
|--------------------------------------------------------------------------
|
| تحت account/ لا في جذر الموقع: كلها تخصّ حساباً، ومجاورتها لـ
| account/messages وaccount/favorites تجعل لوحة المستخدم مكاناً واحداً.
|
| بلا EnsurePhoneIsVerified — بنفس منطق بقية مسارات التفاعل: بوابة الهاتف
| تخصّ *إنشاء* محتوى معروض للسوق، وقراءة إشعار ليست كذلك. والأهم أن
| المشتري غير الموثَّق يتلقى إشعارات فعلاً (جواب على سؤاله)، فحجب الصفحة
| عنه يعني إشعارات لا يستطيع صاحبها فتحها.
|
| read-all قبل {notification}: بلا هذا الترتيب يلتقط المسار المتغيّر
| كلمة read-all كمعرّف، فيرجع 404 على زرٍّ سليم.
|
*/

Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications');

    /*
    | التفضيلات قبل `{notification}` — نفس فخّ read-all.
    |
    | المسار المتغيّر يلتقط `settings` كمعرّف فيرجع 404، والفرق أن هذا
    | مسار GET لا POST فالخطأ يظهر بأول نقرة.
    */
    Route::get('/notifications/settings', [NotificationPreferenceController::class, 'edit'])
        ->name('notifications.preferences');

    Route::put('/notifications/settings', [NotificationPreferenceController::class, 'update'])
        ->name('notifications.preferences.update');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::post('/notifications/{notification}', [NotificationController::class, 'open'])
        ->name('notifications.open');
});
