<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingBlobBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * إعادة بناء العمود المشتق لكل الإعلانات.
 *
 * ضروري لسببين: الإعلانات الموجودة قبل الهجرة عمودها فارغ فتختفي من كل
 * بحث نصي، وتغيير اسم مديرية أو تصنيف لا يمس الإعلانات المرتبطة به.
 *
 * التحديث بـDB::table لا بالموديل، لأن حفظ الموديل يوقظ الـObserver
 * فيعيد حساب السعر والـslug ويحرّك updated_at لآلاف الصفوف بلا سبب.
 */
class RebuildSearchBlobCommand extends Command
{
    protected $signature = 'catalog:rebuild-search-blob {--chunk=200}';

    protected $description = 'إعادة بناء عمود search_blob لكل الإعلانات';

    public function handle(ListingBlobBuilder $builder): int
    {
        $total = Listing::withTrashed()->count();

        if ($total === 0) {
            $this->info('لا توجد إعلانات.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Listing::withTrashed()
            ->chunkById((int) $this->option('chunk'), function ($listings) use ($builder, $bar): void {
                foreach ($listings as $listing) {
                    DB::table('listings')
                        ->where('id', $listing->id)
                        ->update(['search_blob' => $builder->for($listing)]);

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("أُعيد بناء {$total} إعلاناً.");

        return self::SUCCESS;
    }
}