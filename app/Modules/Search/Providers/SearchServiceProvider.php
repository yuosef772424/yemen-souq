<?php

namespace App\Modules\Search\Providers;

use App\Modules\Search\Console\EvaluateSearchCommand;
use App\Modules\Search\Console\PromoteSynonymsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * موفّر وحدة البحث.
 *
 * وُجد لأن الوحدة صارت تملك أوامر artisan. القوالب والهجرات يسجّلها
 * ModuleServiceProvider تلقائياً بمسحه app/Modules، أما الأوامر فلا —
 * ولا أريد جعل المسح التلقائي يخمّن أسماء الأصناف في مجلد Console.
 *
 * يحتاج تسجيلاً واحداً في bootstrap/providers.php — انظر آخر السكربت.
 */
class SearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                EvaluateSearchCommand::class,
                PromoteSynonymsCommand::class,
            ]);
        }
    }
}