<?php

namespace App\Modules\Analytics\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تجميع السجل الخام في جداول قابلة للقراءة.
 *
 * القاعدة: الواجهة ولوحة التشخيص تقرأان من الجداول المجمّعة فقط،
 * أبداً من user_events. السجل الخام ينمو بلا حد؛ استعلامه المباشر
 * في طلب HTTP يبطؤ خطياً حتى يتوقف.
 *
 * التشغيل: php artisan analytics:rollup [--date=YYYY-MM-DD] [--days=7]
 */
class RollupCommand extends Command
{
    protected $signature = 'analytics:rollup
                            {--date= : يوم محدد بصيغة YYYY-MM-DD}
                            {--days=1 : عدد الأيام السابقة لإعادة تجميعها}';

    protected $description = 'تجميع أحداث السلوك في جداول الإحصاءات';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $end = $this->option('date')
    ? Carbon::parse($this->option('date'))
    : Carbon::today();

        for ($i = 0; $i < $days; $i++) {
            $date = $end->copy()->subDays($i)->toDateString();

            $this->components->task("تجميع {$date}", function () use ($date): bool {
                $this->rollupListings($date);
                $this->rollupQueries($date);
                $this->rollupFilters($date);
                $this->rollupFunnel($date);

                return true;
            });
        }

        return self::SUCCESS;
    }

    // ─── إحصاءات الإعلانات ───

    private function rollupListings(string $date): void
    {
        $rows = DB::table('user_events')
            ->selectRaw("
                entity_id as listing_id,
                SUM(event_name = 'listing_viewed') as views,
                COUNT(DISTINCT CASE WHEN event_name = 'listing_viewed' THEN anon_id END) as unique_viewers,
                SUM(event_name = 'contact_revealed') as contacts,
                SUM(event_name = 'search_impression') as search_impressions,
                SUM(event_name = 'search_result_clicked') as search_clicks
            ")
            ->where('entity_type', 'listing')
            ->whereDate('occurred_at', $date)
            ->whereNotNull('entity_id')
            ->groupBy('entity_id')
            ->get();
        foreach ($rows->chunk(200) as $chunk) {
            $payload = [];

            foreach ($chunk as $row) {
                $payload[] = [
                    'listing_id' => $row->listing_id,
                    'stat_date' => $date,
                    'views' => (int) $row->views,
                    'unique_viewers' => (int) $row->unique_viewers,
                    // المفضلة غير مبنية بعد — صفر مقصود لا خطأ حساب
                    'favorites' => 0,
                    'contacts' => (int) $row->contacts,
                    'search_impressions' => (int) $row->search_impressions,
                    'search_clicks' => (int) $row->search_clicks,
                ];
            }

            if ($payload !== []) {
                DB::table('listing_daily_stats')->upsert(
                    $payload,
                    ['listing_id', 'stat_date'],
                    ['views', 'unique_viewers', 'contacts', 'search_impressions', 'search_clicks']
                );
            }
        }
    }

    // ─── إحصاءات الاستعلامات: هنا تُكشف عيوب البحث ───

    private function rollupQueries(string $date): void
    {
        $searches = DB::table('user_events')
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.q_normalized')) as q,
                COUNT(*) as times_searched,
                AVG(JSON_EXTRACT(payload, '$.results')) as avg_results,
                SUM(JSON_EXTRACT(payload, '$.results') = 0) as zero_result_count,
                AVG(JSON_EXTRACT(payload, '$.duration_ms')) as avg_duration_ms
            ")
            ->where('event_name', 'search_performed')
            ->whereDate('occurred_at', $date)
            ->whereRaw("JSON_EXTRACT(payload, '$.q_normalized') IS NOT NULL")
            ->groupBy('q')
            ->get();

        $clicks = DB::table('user_events')
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.q_normalized')) as q,
                COUNT(*) as click_count,
                AVG(JSON_EXTRACT(payload, '$.position')) as avg_click_position
            ")
            ->where('event_name', 'search_result_clicked')
            ->whereDate('occurred_at', $date)
            ->whereRaw("JSON_EXTRACT(payload, '$.q_normalized') IS NOT NULL")
            ->groupBy('q')
            ->get()
            ->keyBy('q');

        $payload = [];

        foreach ($searches as $row) {
            if ($row->q === null || $row->q === '') {
                continue;
            }

            $click = $clicks->get($row->q);

            $payload[] = [
                'query_normalized' => mb_substr($row->q, 0, 180),
                'stat_date' => $date,
                'times_searched' => (int) $row->times_searched,
                'avg_results' => round((float) $row->avg_results, 2),
                'zero_result_count' => (int) $row->zero_result_count,
                'click_count' => (int) ($click->click_count ?? 0),
                'avg_click_position' => $click?->avg_click_position
                    ? round((float) $click->avg_click_position, 2)
                    : null,
                'avg_duration_ms' => (int) round((float) $row->avg_duration_ms),
            ];
        }

        foreach (array_chunk($payload, 200) as $chunk) {
            DB::table('search_query_stats')->upsert(
                $chunk,
                ['query_normalized', 'stat_date'],
                ['times_searched', 'avg_results', 'zero_result_count', 'click_count', 'avg_click_position', 'avg_duration_ms']
            );
        }
    }

    // ─── إحصاءات الفلاتر: يكشف الفلتر المُربك ───

    private function rollupFilters(string $date): void
    {
        $applied = DB::table('user_events')
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.key')) as filter_key,
                COUNT(*) as times_applied,
                AVG(JSON_EXTRACT(payload, '$.results_after')) as avg_results_after,
                SUM(JSON_EXTRACT(payload, '$.results_after') = 0) as zero_after_count
            ")
            ->where('event_name', 'filter_applied')
            ->whereDate('occurred_at', $date)
            ->groupBy('filter_key')
            ->get();
        // الإلغاء السريع للفلتر غير مُتتبَّع بعد: يحتاج قياساً في المتصفح
        // (وقت التطبيق ثم حدث عند الإلغاء). العمود يبقى صفراً حتى ذلك الحين.
        $removedFast = collect();

        $payload = [];

        foreach ($applied as $row) {
            if ($row->filter_key === null) {
                continue;
            }

            $payload[] = [
                'filter_key' => mb_substr($row->filter_key, 0, 60),
                'stat_date' => $date,
                'times_applied' => (int) $row->times_applied,
                'times_removed_fast' => (int) ($removedFast[$row->filter_key] ?? 0),
                'avg_results_after' => round((float) $row->avg_results_after, 2),
                'zero_after_count' => (int) $row->zero_after_count,
            ];
        }

        if ($payload !== []) {
            DB::table('filter_usage_stats')->upsert(
                $payload,
                ['filter_key', 'stat_date'],
                ['times_applied', 'times_removed_fast', 'avg_results_after', 'zero_after_count']
            );
        }
    }

    // ─── مسار التحويل ───

    private function rollupFunnel(string $date): void
    {
        $counts = DB::table('user_events')
            ->selectRaw("
                SUM(event_name = 'search_performed') as searches,
                SUM(event_name = 'search_result_clicked') as clicks,
                SUM(event_name = 'listing_viewed') as listing_views,
                SUM(event_name = 'contact_revealed') as contacts_revealed,
                SUM(event_name = 'listing_created') as listings_created,
                SUM(event_name = 'form_abandoned') as forms_abandoned
            ")
            ->whereDate('occurred_at', $date)
            ->first();

        if ($counts === null) {
            return;
        }

        $sessionsWithClick = DB::table('user_events')
            ->where('event_name', 'search_result_clicked')
            ->whereDate('occurred_at', $date)
            ->distinct()
            ->count('session_id');

        DB::table('funnel_daily')->upsert([[
            'stat_date' => $date,
            'searches' => (int) $counts->searches,
            'searches_with_click' => $sessionsWithClick,
            'listing_views' => (int) $counts->listing_views,
            'contacts_revealed' => (int) $counts->contacts_revealed,
            'listings_created' => (int) $counts->listings_created,
            // النماذج المهجورة غير مُتتبَّعة بعد
            'forms_abandoned' => 0,
        ]], ['stat_date'], [
            'searches', 'searches_with_click', 'listing_views',
            'contacts_revealed', 'listings_created', 'forms_abandoned',
        ]);
    }
}
