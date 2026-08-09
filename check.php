<?php
// check.php
use Illuminate\Support\Facades\DB;

dump([
    'queries' => DB::table('search_query_stats')->where('stat_date', '2026-08-09')
        ->get(['query_normalized', 'times_searched', 'avg_results', 'zero_result_count', 'click_count', 'avg_click_position']),
    'filters' => DB::table('filter_usage_stats')->where('stat_date', '2026-08-09')->get(),
    'funnel' => DB::table('funnel_daily')->where('stat_date', '2026-08-09')->get(),
    'top' => DB::table('listing_daily_stats')->where('stat_date', '2026-08-09')
        ->orderByDesc('search_impressions')->limit(5)
        ->get(['listing_id', 'search_impressions', 'search_clicks', 'views']),
]);