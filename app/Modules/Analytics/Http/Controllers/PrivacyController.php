<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Models\TrackingPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * الانسحاب من التتبّع — الوعد الذي كان مكتوباً وغير منفَّذ.
 *
 * `EventRecorder::allowed` تقرأ `user_tracking_preferences` منذ اليوم
 * الأول، و`README` يَعِد بالانسحاب — ولم يكن للجدول كاتبٌ واحد. أي أن
 * الوعد كان يعمل في اتجاه واحد: الكود يحترم قراراً لا يستطيع أحد
 * اتخاذه.
 *
 * ═══ ولماذا الشاشة هنا لا في وحدة الهوية ═══
 *
 * الجدول تملكه `Analytics`، واتجاه الاعتماد يمنع `Identity` من معرفته.
 * والقاعدة التي تحرسه: **الوحدة التي تملك الجدول تملك شاشته** — فحذف
 * وحدة التحليلات كاملةً يأخذ معها هذه الصفحة، ولا يترك في إعدادات
 * الحساب رابطاً معطَّلاً (الرابط هناك مشروط بـ`Route::has`).
 *
 * ═══ وما لا يفعله الانسحاب ═══
 *
 * لا يمحو ما سُجِّل قبله. المحو يكسر تجميعات يومية بُنيت فعلاً، والوعد
 * المعلَن هو التوقّف عن التسجيل — لا إعادة كتابة التاريخ. والخام يُمحى
 * بعد تسعين يوماً لكل الناس على أي حال.
 */
class PrivacyController
{
    public function edit(Request $request): View
    {
        return view('analytics::privacy', [
            'preference' => $request->user()->trackingPreferences,
        ]);
    }

    /**
     * صفٌّ يُكتب فقط حين يقرّر صاحبه شيئاً.
     *
     * `updateOrCreate` لا `create`: الأغلبية بلا صفّ (الافتراض مسموح)،
     * ومن دخل الصفحة مرتين يجب أن يحدّث صفّه لا أن يصطدم بمفتاحه —
     * والمفتاح هنا `user_id` بلا عمود `id`.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'allow_analytics' => ['nullable', 'boolean'],
            'allow_personalization' => ['nullable', 'boolean'],
        ]);

        $personalization = (bool) ($data['allow_personalization'] ?? false);

        TrackingPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'allow_analytics' => (bool) ($data['allow_analytics'] ?? false),
                'allow_personalization' => $personalization,
                'updated_at' => now(),
            ]
        );

        /*
         * إيقاف التخصيص يمحو الملف المبنيّ فوراً.
         *
         * الأمر الدوري يستثني المنسحبين ويعيد بناء الجدول، لكنه يجري
         * ليلاً — ومن ضغط «أوقف» يستحق أثراً الآن لا غداً. وهذا هو
         * الفرق بين إعدادٍ يعمل وإعدادٍ يَعِد.
         */
        if (! $personalization) {
            DB::table('user_category_affinity')
                ->where('user_id', $request->user()->id)
                ->delete();
        }

        return back()->with('status', 'حُفظت إعدادات الخصوصية.');
    }
}
