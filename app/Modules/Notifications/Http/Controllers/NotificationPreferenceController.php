<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\PreferenceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * شاشة تفضيلات الإشعارات — الواجهة التي كانت تنقص آلةً كاملة.
 *
 * `PreferenceResolver` و`NotificationPreference` وتعداد الأنواع والقنوات
 * كلها مبنيّة منذ وحدة الإشعارات، و`notification_preferences` جدولٌ
 * يُقرأ في كل إرسال — ولم يكن لأحد طريقة إلى كتابته. أي أن المستخدم
 * كان يملك تفضيلات لا يستطيع تغييرها.
 *
 * ═══ القنوات المعروضة هي المنفَّذة وحدها ═══
 *
 * `Channel::isImplemented` تُقصي الرسالة النصية والبريد. وعرضهما مطفأين
 * «للاكتمال» أسوأ من إخفائهما: مربّعٌ يُؤشَّر ولا يصل شيء بعده يُقرأ
 * عطلاً في المنصة.
 *
 * ═══ والأنواع الإجبارية معروضة ومقفلة ═══
 *
 * قرار الإشراف يصل داخل التطبيق مهما كانت الإعدادات — والسبب في
 * `NotificationType::isMandatory`. وإخفاؤه من الشاشة يجعل وصوله يبدو
 * خرقاً للإعداد؛ وعرضه مقفلاً يقول لصاحبه لماذا.
 */
class NotificationPreferenceController
{
    public function __construct(
        private readonly PreferenceResolver $preferences,
    ) {}

    public function edit(Request $request): View
    {
        return view('notifications::preferences', [
            'types' => NotificationType::cases(),
            'channels' => array_filter(
                Channel::cases(),
                static fn (Channel $channel): bool => $channel->isImplemented(),
            ),
            'current' => $this->preferences->allFor($request->user()),
        ]);
    }

    /**
     * الحفظ يمرّ على كل نوع لا على المُرسَل وحده.
     *
     * مربّع اختيار غير مؤشَّر لا يُرسَل أصلاً في HTML. فالقراءة من
     * المُرسَل وحده تعني أن إطفاء إشعار لا يُحفظ أبداً — يُقرأ الغياب
     * كـ«لم يُذكر» لا كـ«أُطفئ». ولذلك المرجع هو التعداد، والمُرسَل
     * يُسأل عن كل خانة فيه.
     */
    public function update(Request $request): RedirectResponse
    {
        $submitted = $request->input('preferences', []);
        $user = $request->user();

        foreach (NotificationType::cases() as $type) {
            $channels = [];

            foreach (Channel::cases() as $channel) {
                if (! $channel->isImplemented()) {
                    continue;
                }

                // الإجباري يُكتب مسموحاً مهما وصل: `PreferenceResolver`
                // يتجاوز التفضيل هنا على أي حال، وصفٌّ يقول `false`
                // بينما الإشعار يصل يجعل الجدول يكذب على من يقرؤه
                $channels[$channel->value] = $type->isMandatory() && $channel === Channel::InApp
                    ? true
                    : (bool) ($submitted[$type->value][$channel->value] ?? false);
            }

            $this->preferences->save($user, $type, $channels);
        }

        return back()->with('status', 'حُفظت تفضيلات الإشعارات.');
    }
}
