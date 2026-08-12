<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * التكرار الذي سبق الحارس.
 *
 * `DuplicateListingGuard` يمنع نسخةً جديدة، ولا يعرف شيئاً عمّا نُشر
 * قبله. وهذا الأمر هو الوجه الثاني: يعرض ما في الجدول الآن، ويطوي
 * النسخ الزائدة عند الطلب.
 *
 * ═══ يعرض بلا `--prune`، ولا يحذف إلا بها ═══
 *
 * أمرٌ يحذف بمجرّد تشغيله يُشغَّل مرةً واحدة بالخطأ. والعرض أولاً يجعل
 * القرار مرئياً قبل وقوعه — وهو نفس سبب `--dry-run` في كل أمر يمسّ
 * بيانات، مقلوباً: الافتراض هو الأمان لا الفعل.
 *
 * ═══ وأيّ نسخة تبقى ═══
 *
 * **الأكثر تفاعلاً**، لا الأقدم ولا الأحدث. الأقدم يبدو الأصل، لكن
 * الازدواج غالباً ضغطتان على زرّ النشر — والمشترون يفتحون أيّهما ظهر
 * لهم أولاً. فالصفّ الذي جمع المشاهدات والتواصل والمفضلة هو الذي
 * يحمل تاريخ الإعلان الحقيقي، وحذفه يمحو أثراً لا يُستعاد.
 *
 * وعند التساوي يفوز الأحدث: أجلُه أبعد.
 *
 * ═══ والطيّ حذف منطقي ═══
 *
 * `delete()` لا `forceDelete()`: النسخة الزائدة قد تكون سلعةً ثانية
 * فعلاً — معرضٌ يملك سيارتين متطابقتين. والمالك يستعيدها من «إعلاناتي»
 * بضغطة، والمحو الحقيقي لا يُستعاد بشيء.
 *
 * التشغيل: php artisan catalog:duplicates [--prune]
 */
class FindDuplicateListingsCommand extends Command
{
    protected $signature = 'catalog:duplicates
                            {--prune : اطوِ النسخ الزائدة بحذف منطقي}';

    protected $description = 'كشف الإعلانات المكرَّرة لنفس المعلن — وطيّها عند الطلب';

    /** نفس حالات `DuplicateListingGuard` — الحيّ وحده يُحسب ازدواجاً */
    private const LIVE_STATUSES = ['draft', 'pending', 'active', 'paused'];

    public function handle(): int
    {
        $groups = DB::table('listings')
            ->select('user_id', 'fingerprint', DB::raw('COUNT(*) as copies'))
            ->whereNull('deleted_at')
            ->whereNotNull('fingerprint')
            ->whereIn('status', self::LIVE_STATUSES)
            ->groupBy('user_id', 'fingerprint')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->components->info('لا إعلانات مكرَّرة.');

            return self::SUCCESS;
        }

        $pruned = 0;

        foreach ($groups as $group) {
            $copies = Listing::query()
                ->where('user_id', $group->user_id)
                ->where('fingerprint', $group->fingerprint)
                ->whereIn('status', self::LIVE_STATUSES)
                ->get(['id', 'title', 'status', 'views_count', 'contacts_count', 'favorites_count', 'created_at']);

            $keep = $copies->sortByDesc(fn (Listing $listing): array => [
                $listing->contacts_count,
                $listing->favorites_count,
                $listing->views_count,
                $listing->id,
            ])->first();

            $this->line('');
            $this->components->twoColumnDetail(
                "<fg=yellow>{$copies->first()->title}</>",
                "معلن {$group->user_id} · {$group->copies} نسخ"
            );

            foreach ($copies as $copy) {
                $mark = $copy->is($keep) ? '<fg=green>يبقى </>' : '<fg=red>يُطوى</>';

                $this->components->twoColumnDetail(
                    "  {$mark} #{$copy->id}",
                    "مشاهدات {$copy->views_count} · تواصل {$copy->contacts_count} · {$copy->created_at}"
                );
            }

            if (! $this->option('prune')) {
                continue;
            }

            foreach ($copies as $copy) {
                if (! $copy->is($keep)) {
                    $copy->delete();
                    $pruned++;
                }
            }
        }

        $this->line('');

        if (! $this->option('prune')) {
            $this->components->warn(
                $groups->count().' مجموعة مكرَّرة. أعد الأمر بـ--prune لطيّ الزائد (حذف منطقي يُستعاد).'
            );

            return self::SUCCESS;
        }

        $this->components->info("طُويت {$pruned} نسخة زائدة. أصحابها يستعيدونها من «إعلاناتي».");

        return self::SUCCESS;
    }
}
