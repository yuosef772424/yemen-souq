<?php

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Enums\AdminAction;
use App\Modules\Admin\Services\AuditLogger;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * خطّ أساس لطابور المراجعة.
 *
 * ═══ المشكلة التي يحلّها ═══
 *
 * اللوحة تُفتح فتجد «160 إعلاناً لم تُراجَع». ولا أحد منها ينتظر قراراً
 * فعلاً: كلها موجودة من قبل أن تُوجد اللوحة، ومعظمها من البذور. وطابور
 * يبدأ بمئة وستين عنصراً لا يفرغه أحد — فيتعلّم المشرف أن الرقم زينة،
 * ثم لا يرى الإعلان الحادي والستين بعد المئة وهو الوحيد الذي يحتاجه.
 *
 * الطابور أداة عمل. وقيمته كلها في أنه يُفرَغ.
 *
 * ═══ البديل المرفوض ═══
 *
 * UPDATE listings SET reviewed_at = NOW() من سطر أوامر MySQL. النتيجة
 * نفسها في الجدول، والفرق أن لا أثر لها في admin_actions — فبعد ستة
 * أشهر لا يمكن معرفة هل رُوجعت تلك الإعلانات أم استُثنيت، ومن استثناها.
 * الاختصار الذي يوفّر دقيقة اليوم يُفقد الجواب إلى الأبد.
 *
 * ═══ صفٌّ واحد في السجل لا مئة وستون ═══
 *
 * الفعل واحد: قرار واحد في لحظة واحدة على مجموعة. تسجيله كمئة وستين
 * إجراء يغرق سجل المساءلة ويجعل «آخر الإجراءات» في اللوحة بلا فائدة
 * ليوم كامل. والصفّ الواحد يحمل العدد والتاريخ الفاصل في meta — وهو كل
 * ما يُسأل عنه لاحقاً.
 *
 *     php artisan admin:baseline-reviewed --dry-run
 *     php artisan admin:baseline-reviewed --before=2026-08-11
 */
class BaselineReviewedCommand extends Command
{
    protected $signature = 'admin:baseline-reviewed
                            {--before= : ختم ما أُنشئ قبل هذا التاريخ (Y-m-d) — الافتراضي: الكل}
                            {--dry-run : اعرض العدد ولا تكتب شيئاً}';

    protected $description = 'ختم الإعلانات القائمة كـمراجَعة لتفريغ طابور اللوحة من الضجيج';

    public function handle(AuditLogger $audit): int
    {
        $before = $this->option('before');

        if ($before !== null && Carbon::hasFormat((string) $before, 'Y-m-d') === false) {
            $this->components->error('صيغة --before يجب أن تكون Y-m-d.');

            return self::FAILURE;
        }

        $query = Listing::query()
            ->whereNull('reviewed_at')
            ->when($before !== null, fn ($q) => $q->where('created_at', '<', Carbon::parse($before)->startOfDay()));

        $total = $query->count();

        if ($total === 0) {
            $this->components->info('لا إعلانات بلا مراجعة — الطابور فارغ.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->info("{$total} إعلاناً سيُختَم. لم يُكتب شيء (--dry-run).");

            return self::SUCCESS;
        }

        /**
         * reviewed_by يبقى فارغاً بقصد.
         *
         * العمود يعني «المشرف الذي رأى هذا الإعلان». ولم يره أحد. وضع
         * معرّف من شغّل الأمر فيه يكتب في الجدول ادّعاءً لم يحدث — وهو
         * بالضبط ما تتجنّبه بقية المشروع في كل عمود.
         */
        DB::transaction(function () use ($query, $total, $before, $audit): void {
            $query->toBase()->update(['reviewed_at' => now()]);

            $audit->log(null, AdminAction::ListingsBaselineReviewed, null, 'خطّ أساس من الطرفية', [
                'count' => $total,
                'before' => $before,
                'reviewed_by' => null,
                'note' => 'استُثنيت من الطابور، ولم يراجعها بشر',
            ]);
        });

        $this->components->info("{$total} إعلاناً خُتِم كـمراجَع. الطابور الآن يعني الجديد وحده.");
        $this->line('صفّ واحد في admin_actions يحمل العدد — لا صفّ لكل إعلان.');

        return self::SUCCESS;
    }
}
