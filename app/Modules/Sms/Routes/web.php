<?php

use App\Modules\Sms\Http\Controllers\ChallengeController;
use Illuminate\Support\Facades\Route;

/*
| صفحة القناة العكسية — تُكتشف تلقائياً وتُسجَّل تحت مجموعة web.
|
| ولماذا هنا لا في وحدة الهوية: الوحدة تُحذف، وصفحةٌ في Identity تشرح
| قناةً قد لا توجد تبقى بعد الحذف تشير إلى مسارٍ ذهب. والرابط إليها من
| صفحة التوثيق مشروطٌ بـRoute::has — فيختفي وحده.
|
| والاسم `sms.challenge` هو ما يفحصه ذلك الشرط. تغييره يُخفي الرابط بلا
| خطأ.
*/

Route::middleware('auth')->group(function (): void {
    Route::get('/verify-by-sms', [ChallengeController::class, 'show'])->name('sms.challenge');

    // تستطلعها الصفحة عبر JS كل بضع ثوانٍ فيما ينتظر المستخدم رسالته —
    // بلا هذا يوثَّق الحساب فعلياً ولا يعرف صاحبه إلا بعد تحديث يدوي.
    Route::get('/verify-by-sms/status', [ChallengeController::class, 'status'])->name('sms.challenge.status');
});
