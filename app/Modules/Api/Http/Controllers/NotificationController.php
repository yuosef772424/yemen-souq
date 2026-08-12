<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Resources\NotificationResource;
use App\Modules\Api\Support\Pagination;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * صندوق الإشعارات.
 *
 * ═══ رقيق لأن المنطق ليس هنا ═══
 *
 * تعليق متحكّم الويب يقول: «ما تبقّى عرضٌ ووسمُ قراءة — وهو بالضبط ما
 * تعيد وحدة API كتابته لاحقاً بشكل JSON». هذا هو الملف، وقد بقي رقيقاً
 * كما وُعد: الإنشاء في `Notifier`، والتفضيل في `PreferenceResolver`،
 * والشكل في `AppNotification::toApiArray`.
 *
 * ═══ الفتح لا يُوجِّه ═══
 *
 * الويب يسم مقروءاً ثم يحوّل إلى `data.url`. والتطبيق لا يتبع روابط
 * الويب — يفتح شاشته من `data.entity_type` و`entity_id`. فالوسم هنا
 * يُرجع الإشعار نفسه، والتنقّل قرارُ العميل بحمولةٍ يملكها أصلاً.
 *
 * ═══ unread_count نقطة مستقلة ═══
 *
 * التطبيق يعرض الشارة على الأيقونة ويحدّثها دورياً. وجلب الصفحة كاملةً
 * لقراءة رقم واحد يعني نقل عشرين إشعاراً كل دقيقة على شبكة تُحسب فيها
 * الميغابايتات.
 */
class NotificationController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = AppNotification::query()
            ->forUser($request->user())
            ->when(
                $request->boolean('unread_only'),
                fn ($query) => $query->unread()
            )
            ->orderByDesc('created_at')
            ->paginate(Pagination::perPage($request));

        return NotificationResource::collection($notifications)->additional([
            'meta' => [
                'unread' => $this->unread($request),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->unread($request)]);
    }

    public function read(Request $request, AppNotification $notification): JsonResponse
    {
        $this->authorizeOwnership($request, $notification);

        $notification->markRead();

        return response()->json(['data' => new NotificationResource($notification)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        // تحديث جماعي لا حلقة على النماذج: صندوق فيه مئتا إشعار غير
        // مقروء ينتج مئتي استعلام، والنتيجة واحدة
        $marked = AppNotification::query()
            ->forUser($request->user())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'وُسمت كل الإشعارات مقروءة.',
            'marked' => $marked,
        ]);
    }

    private function unread(Request $request): int
    {
        return AppNotification::query()->forUser($request->user())->unread()->count();
    }

    /**
     * الملكية شرط واحد بلا فروع — فلا Policy له.
     *
     * نفس حجّة متحكّم الويب: الإشعار يخصّ صفاً واحداً ومالكاً واحداً،
     * وPolicy لسطر واحد طبقةٌ تُقرأ ولا تُفيد.
     */
    private function authorizeOwnership(Request $request, AppNotification $notification): void
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
    }
}
