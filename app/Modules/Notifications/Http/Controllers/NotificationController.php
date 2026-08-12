<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * واجهة الويب فوق طبقة الإشعارات.
 *
 * ═══ رقيق بقصد ═══
 *
 * لا منطق هنا يخصّ الإشعار نفسه: الإنشاء في Notifier والتفضيل في
 * PreferenceResolver. ما تبقّى عرضٌ ووسمُ قراءة — وهو بالضبط ما تعيد
 * وحدة API كتابته لاحقاً بشكل JSON. كلّما نزل منطقٌ إلى هنا صار على
 * الطبقتين أن تكرّراه.
 *
 * ═══ الفتح بـPOST لا GET ═══
 *
 * فتح الإشعار يغيّر حالة (read_at)، وأي زاحف أو جالب مسبق في المتصفح
 * يمرّ على روابط GET يفرّغ صندوق الإشعارات بلا أن يراه أحد. النموذج
 * أثقل في القالب وأصحّ في السلوك.
 */
class NotificationController
{
    public function index(Request $request): View
    {
        $notifications = AppNotification::query()
            ->forUser($request->user())
            ->orderByDesc('created_at')
            ->paginate((int) config('notifications.per_page', 20));

        return view('notifications::index', [
            'notifications' => $notifications,
            'unread' => AppNotification::query()->forUser($request->user())->unread()->count(),
        ]);
    }

    /**
     * وسم مقروءاً ثم المضيّ إلى الوجهة.
     *
     * الوجهة قد تكون null — إعلان حُذف بعد الإشعار عنه. الرجوع إلى
     * القائمة أفضل من 404 على رابط أرسله النظام نفسه.
     */
    public function open(Request $request, AppNotification $notification): RedirectResponse
    {
        $this->authorizeOwnership($request, $notification);

        $notification->markRead();

        return redirect($notification->url() ?? route('account.notifications'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        // تحديث جماعي لا حلقة على النماذج: صندوق فيه مئتا إشعار غير
        // مقروء ينتج مئتي استعلام، والنتيجة واحدة
        AppNotification::query()
            ->forUser($request->user())
            ->unread()
            ->update(['read_at' => now()]);

        return back()->with('status', 'وُسمت كل الإشعارات مقروءة.');
    }

    /**
     * الملكية شرط واحد بلا فروع — فلا Policy له.
     *
     * صفحات المحادثات والأسئلة لها Policies لأن صلاحياتها مركّبة (طرفٌ
     * في خيط، مالكُ إعلان، سائل). أما الإشعار فيخصّ صفاً واحداً ومالكاً
     * واحداً، وPolicy لسطر واحد طبقةٌ تُقرأ ولا تُفيد.
     */
    private function authorizeOwnership(Request $request, AppNotification $notification): void
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
    }
}
