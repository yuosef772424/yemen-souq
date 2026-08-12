<?php

namespace App\Modules\Api\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * الموفّر يوجد لشيء واحد لا يغطّيه الاكتشاف: حدود المعدّل المسمّاة.
 *
 * ═══ لماذا تُعرَّف هنا ═══
 *
 * `throttle:api-read` في ملف المسارات يشير إلى حدٍّ مسمّى، ولارافل 11+
 * لا يعرّف واحداً افتراضياً — المشروع لم يمرّ بـ`withRouting(api: …)`
 * أصلاً. والاسم غير المعرَّف لا يُهمَل بصمت بل يرمي عند أول طلب، وهو
 * السلوك الصحيح: حدٌّ يظنّه القارئ قائماً وهو غائب أسوأ من خطأ.
 *
 * ═══ المفتاح: المستخدم إن وُجد، وإلا العنوان ═══
 *
 * القياس على العنوان وحده يجمع كل من خلف مشغّل جوّال واحد في عدّاد
 * واحد — وهذا هو الحال الغالب في اليمن. فمستخدمٌ مسجَّل يُقاس بحسابه،
 * والعنوان يبقى للزائر وحده.
 *
 * ═══ والرد JSON لا صفحة ═══
 *
 * استجابة 429 الافتراضية نصّية. التطبيق يقرأ JSON في كل حالة أخرى،
 * فاستثناءٌ واحد يجعله يفشل في تحليل الرد بدل أن يعرض «حاول بعد قليل».
 */
class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->limiter('api-read', 'read');
        $this->limiter('api-write', 'write');

        // على العنوان وحده: من يجرّب كلمات المرور ليس مسجَّلاً بعد،
        // فالقياس بالمستخدم يعني عدّاداً لا يوجد له صاحب
        RateLimiter::for('api-auth', fn (Request $request): Limit => Limit::perMinute(
            (int) config('api.rate_limits.auth', 10)
        )->by($request->ip())->response($this->tooMany(...)));
    }

    private function limiter(string $name, string $configKey): void
    {
        RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute(
            (int) config("api.rate_limits.{$configKey}", 60)
        )->by($request->user()?->id ?: $request->ip())->response($this->tooMany(...)));
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function tooMany(Request $request, array $headers = []): JsonResponse
    {
        return response()->json([
            'message' => 'طلبات كثيرة في وقت قصير. انتظر قليلاً ثم أعد المحاولة.',
        ], 429, $headers);
    }
}
