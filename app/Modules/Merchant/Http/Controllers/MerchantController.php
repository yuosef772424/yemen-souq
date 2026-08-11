<?php

namespace App\Modules\Merchant\Http\Controllers;

use App\Modules\Merchant\Services\InteractionFeed;
use App\Modules\Merchant\Services\MerchantInsights;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * لوحة التاجر.
 *
 * ═══ لكل معلن، لا لمن وُسِم is_business ═══
 *
 * العمود موجود في user_profiles ولا شيء في النظام يكتبه. وربط اللوحة به
 * يعني حجب الأرقام عن كل من قد يصير تاجراً — وهم من نريد أن يزيدوا
 * إعلاناتهم. الترقية إلى «حساب تجاري» قرار تسويقي لاحق يُبنى على من
 * استخدم هذه اللوحة، لا شرطاً لدخولها.
 *
 * ولا Policy هنا: كل استعلام في الخدمتين مقيَّد بـuser_id للمستخدم
 * الحالي، فلا مورد يُطلب بمعرّفه حتى تُفحص ملكيته. الملكية شرط في
 * الاستعلام لا حكم على كائن.
 */
class MerchantController
{
    public function __construct(
        private readonly MerchantInsights $insights,
        private readonly InteractionFeed $feed,
    ) {}

    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $days = (int) $request->integer('days', MerchantInsights::DEFAULT_DAYS);
        $days = in_array($days, [7, 30, 90], true) ? $days : MerchantInsights::DEFAULT_DAYS;

        $rows = $this->insights->perListing($user, $days);

        return view('merchant::dashboard', [
            'days' => $days,
            'rows' => $rows,
            'totals' => $this->insights->totals($rows),
            'signals' => $this->insights->signals($user, $rows),
            'lastRolledUp' => $this->insights->lastRolledUp(),
        ]);
    }

    public function interactions(Request $request): View
    {
        return view('merchant::interactions', [
            'items' => $this->feed->forMerchant($request->user()),
        ]);
    }
}
