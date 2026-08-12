<?php

use App\Modules\Api\Http\Middleware\ForceJsonResponse;
use App\Modules\Sms\Http\Controllers\ChallengeController;
use App\Modules\Sms\Http\Controllers\SmsWorkerController;
use App\Modules\Sms\Http\Middleware\AuthenticateSmsWorker;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| عامل الرسائل النصية
|--------------------------------------------------------------------------
|
| اسم الملف `api.php` وحده هو ما يجعل هذه المسارات تُسجَّل تحت مجموعة
| `api` وبادئة `/api` بلا جلسة ولا CSRF — قاعدة عامة في
| ModuleServiceProvider، لا استثناء لهذه الوحدة.
|
| ═══ وسيطٌ خاص لا auth:sanctum ═══
|
| المستهلك ليس شخصاً بل سكربت على هاتف. والسبب الكامل في
| AuthenticateSmsWorker — خلاصته أن رمزاً شخصياً لكيانٍ غير مستخدم
| يُوثَّق في **كل** مسار محروس في المنصة، لا في هذه الثلاثة وحدها.
|
| ═══ الاعتماد الوحيد خارج الوحدة ═══
|
| `ForceJsonResponse` من وحدة Api. والاتجاه سليم — Sms أعلى من Api في
| الاعتماد ولا يعرفها أحد — لكنه يعني أن حذف وحدة Api يكسر هذا الملف.
| المقايضة مقبولة: البديل نسخةٌ ثانية من وسيطٍ من ثلاثة أسطر، ووسيطان
| يفعلان الشيء نفسه أسوأ من اعتمادٍ معلَن.
|
*/

Route::prefix('v1/sms')
    ->name('api.v1.sms.')
    ->middleware([ForceJsonResponse::class, AuthenticateSmsWorker::class, 'throttle:sms-worker'])
    ->group(function (): void {

        // POST لا GET رغم أنه «قراءة»: السحب يكتب — انظر المتحكّم
        Route::post('/claim', [SmsWorkerController::class, 'claim'])->name('claim');

        Route::post('/confirm', [SmsWorkerController::class, 'confirm'])->name('confirm');

        // القناة العكسية: الهاتف يرفع ما وصله مطابقاً للبادئة وحده
        Route::post('/inbound', [SmsWorkerController::class, 'inbound'])->name('inbound');

        Route::get('/health', [SmsWorkerController::class, 'health'])->name('health');
    });

/*
| تحدّي التوثيق العكسي للتطبيق.
|
| خارج مجموعة العامل: هذه نقطة **مستخدم** لا نقطة هاتف — تُحرَس برمز
| Sanctum الشخصي، والتحدّي يُصدَر لصاحب الرمز لا لرقمٍ في الجسد.
| وخلطها بمجموعة العامل كان سيعني أن سرّ الهاتف يُصدر تحدّيات لأي حساب.
*/

Route::prefix('v1/sms')
    ->name('api.v1.sms.')
    ->middleware([ForceJsonResponse::class, 'auth:sanctum', 'throttle:api-write'])
    ->group(function (): void {
        Route::post('/challenge', [ChallengeController::class, 'issue'])->name('challenge');
    });
