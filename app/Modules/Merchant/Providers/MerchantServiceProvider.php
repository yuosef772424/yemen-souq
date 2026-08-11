<?php

namespace App\Modules\Merchant\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * موفّر وحدة التجار.
 *
 * لا هجرة في هذه الوحدة، وهذا مقصود ويستحق التوضيح: كل ما تحتاجه اللوحة
 * موجود — listing_daily_stats وfavorites وlisting_questions وmessages.
 * الوحدة التي تُقرأ ولا تكتب لا تملك جداول؛ وإضافة جدول «merchant_stats»
 * يعني نسخة ثالثة من نفس الأرقام تتفرّق عن أصلها عند أول تعديل.
 */
class MerchantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/Views', 'merchant');
    }
}
