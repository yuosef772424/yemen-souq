<?php

/**
 * scaffold-gaps.php — إغلاق النواقص المكتشفة في المشروع.
 *
 * التشغيل من جذر المشروع:
 *     php scaffold-gaps.php
 *
 * ما يفعله بإيجاز:
 *   1. ينشئ مسار الإبلاغ (كان جدول listing_reports يُقرأ ولا يُكتب فيه).
 *   2. يوقف كتابة أصفار مُختلقة في listing_daily_stats.favorites.
 *   3. يبني حساب user_category_affinity (جدول موصوف بمعادلة ولا كود له).
 *   4. ينقل ExchangeRateServiceTest إلى مساره الصحيح (كان لا يُشغَّل أصلاً).
 *   5. يحذف 28 ملف .bak ومجلداً باسم مشوّه من شجرة الوحدات.
 *
 * الملفات القائمة تُستبدل كاملة لا تُرقَّع، والاستبدال مبنيّ على نسختك
 * أنت (Modules.zip) مع التعديل مطبَّقاً — فلا يضيع تعليق ولا سطر.
 */

declare(strict_types=1);

$root = __DIR__;

$files = [

'app/Modules/Analytics/Events/EventName.php' => <<<'FILE'
<?php

namespace App\Modules\Analytics\Events;

/**
 * أسماء الأحداث المسموح بها.
 *
 * لماذا enum لا نصوص حرة: خطأ إملائي واحد في اسم حدث يعني بياناً
 * يُكتب ولا يُحسب أبداً، بلا خطأ ولا تحذير. وأسوأ من غياب البيانات
 * هو بيانات ناقصة تظن أنها كاملة.
 */
enum EventName: string
{
    case SearchPerformed = 'search_performed';
    case SearchImpression = 'search_impression';
    case SearchResultClicked = 'search_result_clicked';
    case FilterApplied = 'filter_applied';
    case ListingViewed = 'listing_viewed';
    case ContactRevealed = 'contact_revealed';
    case ListingCreated = 'listing_created';

    /**
     * أُضيفت لأن ما يقيسه الكود صار أكثر مما يعرفه هذا الـenum.
     *
     * favorite_added وfavorite_removed: عمود listing_daily_stats.favorites
     * كان يُكتب صفراً ثابتاً بتعليق «المفضلة غير مبنية بعد» — وقد بُنيت.
     * والرقم الصفري في لوحة المعلن أسوأ من غياب العمود: يقرأه المعلن
     * كأن أحداً لم يحفظ إعلانه.
     *
     * listing_reported: البلاغ حدث سلوك دالّ — تكراره على تصنيف واحد
     * إشارة على مشكلة في ذلك السوق لا على مُبلِّغ واحد.
     */
    case FavoriteAdded = 'favorite_added';
    case FavoriteRemoved = 'favorite_removed';
    case ListingReported = 'listing_reported';
}
FILE,

'app/Modules/Engagement/Services/FavoriteToggler.php' => <<<'FILE'
<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * إضافة الإعلان إلى المفضلة وإزالته، مع العدّاد.
 *
 * ═══ لماذا insertOrIgnore ثم فحص عدد الصفوف المتأثرة ═══
 *
 * العدّاد listings.favorites_count ذاكرة محسوبة، ومصدر الحقيقة جدول
 * favorites. والخطأ الشائع هنا: «أدرج ثم زد العدّاد». ضغطتان متلاحقتان
 * على الزر (أو نقرة مزدوجة على اتصال بطيء) تعطيان إدراجاً واحداً —
 * يمنعه المفتاح المركّب — وزيادتين للعدّاد. فيصبح العدّاد أكبر من عدد
 * الصفوف، ولا شيء يكشف ذلك لاحقاً.
 *
 * insertOrIgnore يُرجع عدد الصفوف المُدرجة فعلاً. العدّاد يزيد إن كان
 * واحداً، ولا يزيد إن كان صفراً. المعاملة تجعل الاثنين ذرّة واحدة.
 *
 * ═══ ولماذا عدّاد أصلاً ═══
 *
 * لأن بطاقة الإعلان في نتائج البحث تعرضه، وحساب COUNT لكل بطاقة في كل
 * صفحة نتائج يعني 24 استعلاماً إضافياً. العدّاد يُقرأ مع الصف نفسه.
 *
 * ═══ ولماذا يُسجَّل حدث أيضاً ═══
 *
 * العدّاد يقول «كم»، ولا يقول «متى». وعمود listing_daily_stats.favorites
 * كان يُكتب صفراً ثابتاً لأن لا حدث يُسجَّل — فلوحة المعلن تعرض صفر
 * حفظات لإعلان محفوظ فعلاً. الحدث هو الفرق بين رقم وتاريخ.
 *
 * والحدث يُسجَّل داخل المعاملة بعد نجاح الكتابة لا قبلها: EventRecorder
 * يخزّن في الذاكرة ويكتب في نهاية الطلب، فتسجيل حدث لإدراج فشل يعني
 * حدثاً بلا صف — وهو أسوأ من صف بلا حدث، لأن التجميع يعتمد عليه.
 */
final class FavoriteToggler
{
    public function __construct(
        private readonly EventRecorder $events,
    ) {}

    /**
     * @return bool صار مفضّلاً (true) أو أُزيل (false)
     */
    public function toggle(User $user, Listing $listing): bool
    {
        return DB::transaction(function () use ($user, $listing): bool {
            $inserted = DB::table('favorites')->insertOrIgnore([
                'user_id' => $user->id,
                'listing_id' => $listing->id,
                'created_at' => now(),
            ]);

            if ($inserted === 1) {
                $this->adjust($listing, 1);

                $this->events->record(EventName::FavoriteAdded, 'listing', $listing->id);

                return true;
            }

            $deleted = DB::table('favorites')
                ->where('user_id', $user->id)
                ->where('listing_id', $listing->id)
                ->delete();

            if ($deleted === 1) {
                $this->adjust($listing, -1);

                $this->events->record(EventName::FavoriteRemoved, 'listing', $listing->id);
            }

            return false;
        });
    }

    public function has(User $user, Listing $listing): bool
    {
        return DB::table('favorites')
            ->where('user_id', $user->id)
            ->where('listing_id', $listing->id)
            ->exists();
    }

    /**
     * العدّاد لا ينزل تحت الصفر.
     *
     * unsignedInteger في المخطط، فقيمة سالبة خطأ SQL يُسقط الطلب. وحدوثه
     * ممكن: صف أُدرج قبل وجود هذه الخدمة، أو عدّاد أُعيد ضبطه يدوياً.
     * الحدّ عند الصفر يجعل العطل انحرافاً في رقم لا صفحة خطأ.
     */
    private function adjust(Listing $listing, int $delta): void
    {
        $query = Listing::withTrashed()->whereKey($listing->getKey());

        if ($delta > 0) {
            $query->increment('favorites_count', $delta);

            return;
        }

        $query->where('favorites_count', '>', 0)->decrement('favorites_count', abs($delta));
    }
}
FILE,

'app/Modules/Analytics/Console/RollupCommand.php' => <<<'FILE'
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
                SUM(event_name = 'favorite_added') as favorites,
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
                    // صافي الإضافات في ذلك اليوم. الإزالة تُسجَّل بحدث
                    // منفصل ولا تُخصم هنا: العمود يعني «كم حُفِظ اليوم»،
                    // وخصم إزالة لحفظٍ من الأسبوع الماضي يُنتج رقماً
                    // سالباً لا يمثّل شيئاً في تاريخه.
                    'favorites' => (int) $row->favorites,
                    'contacts' => (int) $row->contacts,
                    'search_impressions' => (int) $row->search_impressions,
                    'search_clicks' => (int) $row->search_clicks,
                ];
            }

            if ($payload !== []) {
                DB::table('listing_daily_stats')->upsert(
                    $payload,
                    ['listing_id', 'stat_date'],
                    ['views', 'unique_viewers', 'favorites', 'contacts', 'search_impressions', 'search_clicks']
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
FILE,

'app/Modules/Analytics/Providers/AnalyticsServiceProvider.php' => <<<'FILE'
<?php

namespace App\Modules\Analytics\Providers;

use App\Modules\Analytics\Console\ComputeAffinityCommand;
use App\Modules\Analytics\Console\PruneEventsCommand;
use App\Modules\Analytics\Console\RollupCommand;
use App\Modules\Analytics\Services\EventRecorder;
use Illuminate\Support\ServiceProvider;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // singleton ضروري: كل الأحداث في الطلب يجب أن تتجمّع
        // في نفس المخزن المؤقت لتُكتب دفعة واحدة
        $this->app->singleton(EventRecorder::class);
    }

    public function boot(): void
    {
        // الكتابة تحدث بعد إرسال الاستجابة للمتصفح — لا تأخير على المستخدم
        $this->app->terminating(function (): void {
            if ($this->app->resolved(EventRecorder::class)) {
                $this->app->make(EventRecorder::class)->flush();
            }
        });
        if ($this->app->runningInConsole()) {
            $this->commands([
                RollupCommand::class,
                PruneEventsCommand::class,
                ComputeAffinityCommand::class,
            ]);
        }
    }
}
FILE,

'app/Modules/Catalog/Resources/Views/show.blade.php' => <<<'FILE'
@extends('shared::layouts.app')

@section('title', $listing->title)
@section('description', Str::limit($listing->description ?? $listing->title, 150))

@section('content')

<nav aria-label="مسار التصفح" class="mb-4 text-xs text-muted">
    <ol class="flex flex-wrap items-center gap-1.5">
        <li><a href="{{ route('search') }}" class="hover:text-ink hover:underline">الرئيسية</a></li>
        @if ($listing->category?->parent)
            <li aria-hidden="true">/</li>
            <li>
                <a href="{{ route('search', ['category' => $listing->category->parent->id]) }}"
                   class="hover:text-ink hover:underline">{{ $listing->category->parent->name_ar }}</a>
            </li>
        @endif
        @if ($listing->category)
            <li aria-hidden="true">/</li>
            <li>
                <a href="{{ route('search', ['category' => $listing->category->id]) }}"
                   class="hover:text-ink hover:underline">{{ $listing->category->name_ar }}</a>
            </li>
        @endif
    </ol>
</nav>

@if ($isOwner)
    <p class="mb-4 rounded-xl border border-line bg-tint px-4 py-2.5 text-xs">
        هذه معاينة إعلانك — زياراتك له لا تُحسب في المشاهدات.
        <a href="{{ route('account.listings.edit', $listing) }}" class="font-medium text-brand-dark hover:underline">تعديل</a>
    </p>
@endif

<div class="grid gap-6 lg:grid-cols-[1fr_340px]">

    <div class="space-y-5">

        {{-- معرض الصور --}}
        <div x-data="{ active: 0 }" class="overflow-hidden rounded-2xl border border-line bg-white">
            <div class="aspect-[16/10] bg-tint">
                @forelse ($listing->images as $index => $image)
                    <img @if ($index > 0) x-show="active === {{ $index }}" x-cloak
                         @else x-show="active === 0" @endif
                         src="{{ asset('storage/' . $image->path) }}"
                         alt="{{ $listing->title }} — صورة {{ $index + 1 }}"
                         class="size-full object-cover">
                @empty
                    <div class="grid size-full place-items-center text-sm text-muted">لا توجد صور لهذا الإعلان</div>
                @endforelse
            </div>

            @if ($listing->images->count() > 1)
                <div class="flex gap-2 overflow-x-auto p-3">
                    @foreach ($listing->images as $index => $image)
                        <button type="button" @click="active = {{ $index }}"
                                :class="active === {{ $index }} ? 'ring-2 ring-brand' : 'ring-1 ring-line'"
                                class="size-16 shrink-0 overflow-hidden rounded-lg"
                                aria-label="الصورة {{ $index + 1 }}">
                            <img src="{{ asset('storage/' . $image->path) }}" alt="" class="size-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- العنوان والتفاصيل --}}
        <div class="rounded-2xl border border-line bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h1 class="text-xl font-bold leading-snug">{{ $listing->title }}</h1>
                @if ($listing->isFeaturedNow())
                    <span class="rounded-md bg-ink px-2 py-0.5 text-[11px] font-semibold text-white">مميّز</span>
                @endif
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                @if ($listing->condition_type)
                    <div>
                        <dt class="text-xs text-muted">الحالة</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ ['new' => 'جديد', 'like_new' => 'كالجديد', 'used' => 'مستعمل', 'for_parts' => 'قطع غيار'][$listing->condition_type] ?? '—' }}
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs text-muted">الموقع</dt>
                    <dd class="mt-0.5 font-medium">{{ $listing->location?->name_ar }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">النشر</dt>
                    <dd class="mt-0.5 font-medium">{{ $listing->published_at?->diffForHumans() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">المشاهدات</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">{{ number_format($listing->views_count) }}</dd>
                </div>
            </dl>

            @if ($listing->description)
                <div class="mt-5 border-t border-line pt-5">
                    <h2 class="text-sm font-semibold">الوصف</h2>
                    <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink/85">{{ $listing->description }}</p>
                </div>
            @endif
        </div>

        {{-- الأسئلة العامة: الوحدة تملك القالب، والصفحة تؤلّف --}}
        @include('engagement::partials.questions', [
            'listing' => $listing,
            'questions' => $questions,
            'canAsk' => $canAsk,
            'isOwner' => $isOwner,
        ])
    </div>

    {{-- العمود الجانبي --}}
    <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">

        <div class="rounded-2xl border border-line bg-white p-5">
            <p class="text-2xl font-bold tabular-nums text-brand-dark">{{ $listing->formattedPrice() }}</p>

            @if ($listing->is_negotiable)
                <p class="mt-1 text-xs text-muted">قابل للتفاوض</p>
            @endif

            @if ($equivalents !== [])
                <div class="mt-4 border-t border-line pt-4">
                    <p class="text-xs font-semibold text-muted">يقابل تقريباً</p>
                    <ul class="mt-2 space-y-1.5 text-sm tabular-nums">
                        @foreach ($equivalents as $equivalent)
                            <li class="flex items-baseline justify-between gap-3">
                                <span class="text-muted">{{ $equivalent['currency']->name_ar }}</span>
                                <span class="font-medium">{{ $equivalent['currency']->format($equivalent['amount']) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($listing->rate_date)
                        <p class="mt-3 text-[11px] leading-relaxed text-muted">
                            محسوب بسعر صرف {{ $listing->rate_date->translatedFormat('j F Y') }}.
                            السعر المعتمد هو ما حدده المعلن بعملته الأصلية.
                        </p>
                    @endif
                </div>
            @endif

            {{-- المفضلة: نموذج POST لا زرّ Alpine.
                 الحفظ يجب أن يعمل بلا JavaScript — وهو أول ما يسقط على
                 اتصال ضعيف، وهناك بالضبط يحفظ الناس ليعودوا لاحقاً --}}
            @auth
                <form method="POST" action="{{ route('listing.favorite', $listing) }}" class="mt-4">
                    @csrf
                    <button type="submit"
                            @class([
                                'w-full rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
                                'border-brand bg-brand/5 text-brand-dark' => $isFavorited,
                                'border-line hover:bg-tint' => ! $isFavorited,
                            ])>
                        {{ $isFavorited ? '★ في المفضلة' : '☆ أضف إلى المفضلة' }}
                    </button>
                </form>
            @endauth
        </div>

        <div class="rounded-2xl border border-line bg-white p-5">
            <p class="text-sm font-semibold">{{ $listing->user?->displayName() ?? 'معلن' }}</p>
            <p class="mt-0.5 text-xs text-muted">عضو منذ {{ $listing->user?->created_at?->translatedFormat('F Y') }}</p>

            @if ($listing->contact_phone && ! $listing->hide_phone)
                <div x-data="contactReveal('{{ $listing->slug }}')" class="mt-4">
                    <button type="button" x-show="!phone" :disabled="loading" @click="reveal()"
                            class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:opacity-60">
                        <span x-text="loading ? 'جارٍ الجلب…' : 'إظهار رقم التواصل'"></span>
                    </button>

                    <a x-show="phone" x-cloak :href="'tel:' + phone" x-text="phone"
                       class="block w-full rounded-xl border border-brand px-4 py-3 text-center text-sm font-semibold tabular-nums text-brand-dark"></a>

                    <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs text-red-700"></p>
                </div>
            @endif

            {{-- المراسلة الخاصة: هنا يجوز تبادل الأرقام، وفي السؤال العام لا --}}
            @if ($canMessage)
                <form method="POST" action="{{ route('listing.messages.store', $listing) }}" class="mt-4 border-t border-line pt-4">
                    @csrf
                    <label for="message-body" class="block text-xs font-semibold">راسل المعلن</label>
                    <textarea id="message-body" name="body" rows="3" maxlength="2000" required
                              placeholder="السلام عليكم، هل الإعلان ما زال متاحاً؟"
                              class="mt-1.5 w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">{{ old('body') }}</textarea>

                    @error('body')
                        <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                    @enderror

                    <button type="submit"
                            class="mt-2 w-full rounded-xl border border-line px-4 py-2.5 text-sm font-semibold transition hover:bg-tint">
                        إرسال رسالة
                    </button>
                </form>
            @elseif (! auth()->check())
                <p class="mt-4 border-t border-line pt-4 text-xs text-muted">
                    <a href="{{ route('login') }}" class="font-medium text-brand-dark hover:underline">سجّل الدخول</a>
                    لمراسلة المعلن.
                </p>
            @endif

            <p class="mt-3 text-[11px] leading-relaxed text-muted">
                افحص المنتج قبل الدفع، ولا تحوّل مبالغ مقدماً لمن لا تعرفه.
            </p>
        </div>
    </aside>
</div>

@if (count($similar) > 0)
    <section class="mt-10">
        <h2 class="text-base font-semibold">إعلانات مشابهة في القيمة</h2>
        <p class="mt-1 text-xs text-muted">المقارنة بالقيمة الموحّدة، لا بالرقم المكتوب</p>

        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($similar as $item)
                @include('search::partials.listing-card', ['listing' => $item, 'baseCurrency' => $baseCurrency])
            @endforeach
        </ul>
    </section>
@endif

{{-- الإبلاغ آخر الصفحة — انظر تعليق القالب المضمَّن --}}
@include('engagement::partials.report', ['listing' => $listing])

@endsection
FILE,

'app/Modules/Engagement/Services/ReportSubmitter.php' => <<<'FILE'
<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\ListingReport;
use Illuminate\Support\Facades\DB;

/**
 * استقبال البلاغ من المستخدم.
 *
 * ═══ لماذا وُجد هذا الملف ═══
 *
 * جدول listing_reports كان موجوداً من أول هجرة، ولوحة التحكم صارت تقرأه
 * وتفصل فيه — ولا شيء في الموقع يكتب فيه صفاً واحداً. أي أن طابور
 * البلاغات كان مضموناً أن يبقى فارغاً إلى الأبد. لوحة تعمل على جدول لا
 * يكتب فيه أحد ليست ميزة مؤجَّلة بل ميزة كاذبة.
 *
 * ═══ الإبلاغ للمسجَّلين وحدهم ═══
 *
 * البديل المرفوض: السماح للزائر (reporter_id فارغ — والعمود يقبل الفراغ).
 * مرفوض لأن البلاغ سلاح: من يبلّغ على منافسه ثلاث مرات في يوم يجب أن
 * يكون معروفاً. وبلا هوية للمُبلِّغ لا معنى لإبقاء البلاغات المصروفة —
 * وهي الإشارة الوحيدة التي تكشف الإساءة (انظر ReportResolver).
 *
 * ═══ بلاغ واحد مفتوح لكل مستخدم على كل إعلان ═══
 *
 * البديل المرفوض: فهرس فريد على (listing_id, reporter_id) في المخطط.
 * يبدو أنظف، لكنه يمنع المستخدم من الإبلاغ مرة أخرى بعد أن صُرِف بلاغه
 * الأول وتغيّر الإعلان — والحالة مشروعة: أعاد المعلن نشر نفس المخالفة.
 * الشرط الصحيح شرط على المفتوح لا على كل التاريخ، وهذا لا يُعبَّر عنه
 * بفهرس فريد.
 */
final class ReportSubmitter
{
    /** أسباب مغلقة لأنها تُوجَّه للفرز لا للقراءة — تطابق enum المخطط */
    public const REASONS = ['spam', 'fraud', 'wrong_category', 'prohibited', 'duplicate', 'other'];

    public function __construct(
        private readonly EventRecorder $events,
    ) {}

    /** المالك لا يبلّغ على نفسه، وما فُتح لا يُفتح مرتين */
    public function canReport(?User $user, Listing $listing): bool
    {
        if ($user === null || $user->id === $listing->user_id) {
            return false;
        }

        return ! $this->hasOpenReport($user, $listing);
    }

    public function hasOpenReport(User $user, Listing $listing): bool
    {
        return ListingReport::query()
            ->where('listing_id', $listing->id)
            ->where('reporter_id', $user->id)
            ->open()
            ->exists();
    }

    public function submit(User $user, Listing $listing, string $reason, ?string $details = null): ?ListingReport
    {
        if (! $this->canReport($user, $listing)) {
            return null;
        }

        $report = DB::transaction(fn (): ListingReport => ListingReport::create([
            'listing_id' => $listing->id,
            'reporter_id' => $user->id,
            'reason' => in_array($reason, self::REASONS, true) ? $reason : 'other',
            'details' => $details === null ? null : mb_substr(trim($details), 0, 500),
            'status' => 'open',
            'created_at' => now(),
        ]));

        /**
         * الحدث يحمل السبب ولا يحمل النص.
         *
         * قاعدة الوحدة: لا بيانات شخصية في payload. ونص البلاغ قد يحتوي
         * اسماً أو رقماً كتبه المُبلِّغ — فيبقى في listing_reports حيث
         * يُقرأ بصلاحية، لا في سجل تحليلي يُصدَّر ويُجمَّع.
         */
        $this->events->record(EventName::ListingReported, 'listing', $listing->id, [
            'reason' => $report->reason,
        ]);

        return $report;
    }
}
FILE,

'app/Modules/Engagement/Http/Requests/StoreListingReportRequest.php' => <<<'FILE'
<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Engagement\Services\ReportSubmitter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreListingReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(ReportSubmitter::REASONS)],

            /**
             * التفاصيل مطلوبة مع «أخرى» وحدها.
             *
             * إجبار كل مُبلِّغ على الكتابة يقلّل البلاغات الصحيحة: من يرى
             * سلعة ممنوعة يريد ضغطة لا مقالاً. و«أخرى» بلا شرح بلاغ لا
             * يمكن الفصل فيه — فيبقى في الطابور حتى يُصرَف بلا قراءة.
             */
            'details' => ['nullable', 'string', 'max:500', 'required_if:reason,other', 'min:10'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'السبب', 'details' => 'التفاصيل'];
    }

    public function messages(): array
    {
        return [
            'details.required_if' => 'اشرح المخالفة عند اختيار «أخرى» — بلا شرح لا يمكن الفصل في البلاغ.',
        ];
    }
}
FILE,

'app/Modules/Engagement/Http/Controllers/ListingReportController.php' => <<<'FILE'
<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\StoreListingReportRequest;
use App\Modules\Engagement\Services\ReportSubmitter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;

class ListingReportController
{
    public function __construct(
        private readonly ReportSubmitter $reports,
    ) {}

    /**
     * الحدّ: خمسة بلاغات في الساعة للحساب الواحد.
     *
     * البديل المرفوض: بلا حدّ، والاعتماد على «بلاغ واحد لكل إعلان». ذلك
     * الشرط يمنع التكرار على *إعلان واحد* ولا يمنع حساباً واحداً من
     * الإبلاغ على خمسين إعلاناً لمنافس في دقيقتين — وهو الهجوم الحقيقي
     * لأنه يُغرق الطابور فيتوقف الإشراف عن العمل.
     *
     * والرسالة تقول السبب صريحاً. «حدث خطأ» يجعل المُبلِّغ الصادق يعيد
     * المحاولة ويظن الموقع معطوباً.
     */
    public function store(StoreListingReportRequest $request, Listing $listing): RedirectResponse
    {
        $key = 'report:' . $request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->with('error', 'بلاغات كثيرة في وقت قصير. أعد المحاولة بعد ساعة.');
        }

        $report = $this->reports->submit(
            $request->user(),
            $listing,
            $request->string('reason')->value(),
            $request->input('details'),
        );

        if ($report === null) {
            return back()->with('error', 'لديك بلاغ مفتوح على هذا الإعلان، أو أنه إعلانك.');
        }

        RateLimiter::hit($key, 3600);

        return back()->with('status', 'وصل بلاغك وسيُراجَع. شكراً — البلاغات هي ما يجعل السوق نظيفاً.');
    }
}
FILE,

'app/Modules/Engagement/Routes/reports.php' => <<<'FILE'
<?php

use App\Modules\Engagement\Http\Controllers\ListingReportController;
use Illuminate\Support\Facades\Route;

/*
| مسار الإبلاغ — ملف منفصل بقصد.
|
| بقية مسارات وحدة التفاعل معرَّفة في routes/web.php. إضافة هذا المسار
| هناك تعني تعديلاً في وسط ملف قائم؛ وملف صغير مستقل يُضاف بسطر require
| واحد في نهايته. المقايضة: مسارات الوحدة موزَّعة على موضعين — يُجمعان
| في ملف واحد للوحدة عند أول إعادة تنظيم.
*/

Route::middleware('auth')->group(function (): void {
    Route::post('/listings/{listing}/report', [ListingReportController::class, 'store'])
        ->name('listing.report');
});
FILE,

'app/Modules/Engagement/Resources/Views/partials/report.blade.php' => <<<'FILE'
{{--
    زرّ الإبلاغ.

    موضعه آخر الصفحة وبحجم صغير بقصد. ليس تقليلاً من أهميته: الإبلاغ
    فعل نادر يُبحث عنه عند الحاجة، ووضعه بجانب «راسل المعلن» بنفس الحجم
    يجعل جزءاً من الزوار يضغطه بالخطأ — فيتضخّم الطابور ببلاغات لا معنى
    لها ويتوقف الإشراف عن العمل. الظهور بالطلب (details/summary) يكفي.

    والنموذج يظهر للمسجَّل غير المالك وحده — انظر ReportSubmitter.
--}}
@auth
    @if (auth()->id() !== $listing->user_id)
        @php $reporter = app(\App\Modules\Engagement\Services\ReportSubmitter::class); @endphp

        <div class="mt-10 border-t border-line pt-5">
            @if ($reporter->hasOpenReport(auth()->user(), $listing))
                <p class="text-xs text-muted">لديك بلاغ مفتوح على هذا الإعلان — قيد المراجعة.</p>
            @else
                <details class="group">
                    <summary class="cursor-pointer text-xs text-muted transition hover:text-ink">
                        الإبلاغ عن هذا الإعلان
                    </summary>

                    <form method="POST" action="{{ route('listing.report', $listing) }}"
                          class="mt-3 max-w-md rounded-2xl border border-line bg-white p-4">
                        @csrf

                        <label for="report-reason" class="block text-xs font-semibold">السبب</label>
                        <select id="report-reason" name="reason" required
                                class="mt-1.5 w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">
                            <option value="fraud">نصب أو احتيال</option>
                            <option value="prohibited">سلعة ممنوعة</option>
                            <option value="spam">إعلان دعائي أو مكرر</option>
                            <option value="wrong_category">تصنيف خاطئ</option>
                            <option value="duplicate">نسخة مكررة</option>
                            <option value="other">أخرى</option>
                        </select>

                        <label for="report-details" class="mt-3 block text-xs font-semibold">
                            تفاصيل <span class="font-normal text-muted">(مطلوبة مع «أخرى»)</span>
                        </label>
                        <textarea id="report-details" name="details" rows="3" maxlength="500"
                                  placeholder="ما المخالفة تحديداً؟"
                                  class="mt-1.5 w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">{{ old('details') }}</textarea>

                        @error('details')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                        @error('reason')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror

                        <button type="submit"
                                class="mt-3 rounded-lg border border-line px-4 py-2 text-xs font-semibold text-muted transition hover:text-ink">
                            إرسال البلاغ
                        </button>

                        <p class="mt-2 text-[11px] leading-relaxed text-muted">
                            البلاغ الكاذب يُصرَف ويُسجَّل. الإبلاغ على منافس لا يخفي إعلانه.
                        </p>
                    </form>
                </details>
            @endif
        </div>
    @endif
@else
    <p class="mt-10 border-t border-line pt-5 text-xs text-muted">
        <a href="{{ route('login') }}" class="font-medium text-brand-dark hover:underline">سجّل الدخول</a>
        للإبلاغ عن إعلان مخالف.
    </p>
@endauth
FILE,

'app/Modules/Analytics/Console/ComputeAffinityCommand.php' => <<<'FILE'
<?php

namespace App\Modules\Analytics\Console;

use App\Modules\Analytics\Events\EventName;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * حساب user_category_affinity.
 *
 * ═══ لماذا وُجد ═══
 *
 * الجدول موجود من أول هجرة، وتعليق العمود score يصف المعادلة بالحرف
 * («مشاهدة=1، مفضلة=5، تواصل=10، مع تلاشٍ زمني») — ولا كود في المشروع
 * يكتب فيه صفاً. جدول فارغ بمعادلة موصوفة هو أسوأ من جدول غير موجود:
 * من يقرأ المخطط يظن الميزة قائمة.
 *
 * ═══ التلاشي الزمني، والبديل المرفوض ═══
 *
 * البديل المرفوض: مجموع الأوزان بلا تلاشٍ. مرفوض لأن من تصفّح السيارات
 * شهرين ثم انتقل إلى الأثاث يبقى «مهتماً بالسيارات» إلى الأبد — فيصير
 * التخصيص نصباً تذكارياً لاهتمام انتهى. نصف العمر 21 يوماً: سلوك اليوم
 * يزن ضعف سلوك ما قبل ثلاثة أسابيع.
 *
 * ═══ ولماذا الحساب في PHP لا في SQL ═══
 *
 * POW وDATEDIFF تختلف بين MySQL وSQLite، والاختبارات تجري على محرك قد
 * يخالف الإنتاج. الاستعلام يجمّع (مستخدم، تصنيف، يوم، حدث) — وهو تجميع
 * يقلّص السجل الخام إلى صفوف قليلة — والتلاشي يُحسب على تلك الصفوف.
 * كلفة الحساب في PHP لا شيء بعد التجميع، والنتيجة واحدة على أي محرك.
 *
 * ═══ وما لا يفعله ═══
 *
 * لا يورّث الدرجة إلى التصنيف الأب. مشاهدة «آيفون» لا تُقوّي «إلكترونيات»
 * كلها. الوراثة تحتاج قراراً في نسبة التسريب بين المستويات، وأي رقم
 * أختاره الآن رقم مُختلَق — والوحدة قائمة على تجنّب ذلك بالضبط.
 *
 * التشغيل: php artisan analytics:affinity [--days=90]
 */
class ComputeAffinityCommand extends Command
{
    protected $signature = 'analytics:affinity
                            {--days=90 : نافذة السلوك المحسوبة}';

    protected $description = 'حساب ميل المستخدمين إلى التصنيفات من سلوكهم';

    /** نصف العمر بالأيام */
    public const HALF_LIFE_DAYS = 21;

    /** أوزان الأحداث — كما في تعليق عمود score */
    private const WEIGHTS = [
        'listing_viewed' => 1.0,
        'favorite_added' => 5.0,
        'contact_revealed' => 10.0,
    ];

    public function handle(): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $from = Carbon::today()->subDays($days - 1);
        $today = Carbon::today();

        $rows = DB::table('user_events')
            ->join('listings', 'listings.id', '=', 'user_events.entity_id')
            ->whereNotNull('user_events.user_id')
            ->where('user_events.entity_type', 'listing')
            ->whereIn('user_events.event_name', array_keys(self::WEIGHTS))
            ->where('user_events.occurred_at', '>=', $from->startOfDay())
            ->selectRaw('user_events.user_id as user_id')
            ->selectRaw('listings.category_id as category_id')
            ->selectRaw('DATE(user_events.occurred_at) as day')
            ->selectRaw('user_events.event_name as event_name')
            ->selectRaw('COUNT(*) as hits')
            ->groupBy('user_events.user_id', 'listings.category_id', 'day', 'user_events.event_name')
            ->get();

        if ($rows->isEmpty()) {
            $this->components->warn('لا أحداث سلوك في النافذة — لا شيء ليُحسَب.');

            return self::SUCCESS;
        }

        /** @var array<string, array{user_id:int, category_id:int, score:float, last:string}> $scores */
        $scores = [];

        foreach ($rows as $row) {
            $weight = self::WEIGHTS[$row->event_name] ?? 0.0;

            if ($weight === 0.0 || $row->category_id === null) {
                continue;
            }

            $age = $today->diffInDays(Carbon::parse($row->day), true);
            $decay = 2 ** (-$age / self::HALF_LIFE_DAYS);

            $key = $row->user_id . ':' . $row->category_id;

            $scores[$key] ??= [
                'user_id' => (int) $row->user_id,
                'category_id' => (int) $row->category_id,
                'score' => 0.0,
                'last' => (string) $row->day,
            ];

            $scores[$key]['score'] += $weight * $row->hits * $decay;
            $scores[$key]['last'] = max($scores[$key]['last'], (string) $row->day);
        }

        /**
         * الجدول يُفرَّغ ثم يُكتب، لا يُحدَّث تراكمياً.
         *
         * الدرجة دالة على النافذة كلها: صفٌّ قديم لمستخدم توقّف نشاطه
         * يجب أن يختفي لا أن يبقى بقيمته الأخيرة. والتحديث التراكمي
         * يجعل الجدول أرشيفاً لا صورة للحال.
         */
        DB::transaction(function () use ($scores): void {
            DB::table('user_category_affinity')->truncate();

            foreach (array_chunk(array_values($scores), 500) as $chunk) {
                DB::table('user_category_affinity')->insert(array_map(fn (array $row): array => [
                    'user_id' => $row['user_id'],
                    'category_id' => $row['category_id'],
                    'score' => round($row['score'], 4),
                    'last_event_at' => $row['last'] . ' 00:00:00',
                    'computed_at' => now(),
                ], $chunk));
            }
        });

        $this->components->info(count($scores) . ' صفَّ ميل مبنيّ من ' . $rows->count() . ' صفَّ سلوك مجمَّع.');

        // إشارة على أن الأوزان تقرأ أحداثاً موجودة فعلاً — انظر HealthCheck
        $missing = array_values(array_diff(
            array_keys(self::WEIGHTS),
            DB::table('user_events')->distinct()->pluck('event_name')->all()
        ));

        if ($missing !== []) {
            $this->components->warn('أحداث بوزن ولا وجود لها في السجل: ' . implode('، ', $missing));
        }

        return self::SUCCESS;
    }
}
FILE,

'tests/Feature/Concerns/SeedsMarketplace.php' => <<<'FILE'
<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Database\Seeders\CategorySeeder;
use App\Modules\Shared\Database\Seeders\CurrencySeeder;
use App\Modules\Shared\Database\Seeders\LocationSeeder;
use Illuminate\Support\Str;

/**
 * تجهيز مشترك لاختبارات لا تخص وحدة التحكم.
 *
 * منفصل عن MakesAdminUsers بقصد: ذلك السمة يخص الأدوار والصلاحيات،
 * وهذا يخص السوق نفسه. دمجهما يجعل كل اختبار في المشروع يعتمد على
 * وحدة التحكم — فلا تُحذف الوحدة ولا تُنقل بلا كسر الاختبارات كلها.
 */
trait SeedsMarketplace
{
    protected function seedMarketplace(): void
    {
        $this->seed(CurrencySeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(LocationSeeder::class);
    }

    protected function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes);
    }

    /** حدث سلوك خام — بلا مرور بـEventRecorder */
    protected function recordRawEvent(
        string $eventName,
        ?User $user,
        ?int $listingId,
        string $day,
    ): void {
        \Illuminate\Support\Facades\DB::table('user_events')->insert([
            'event_name' => $eventName,
            'occurred_at' => $day . ' 12:00:00',
            'user_id' => $user?->id,
            'anon_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'entity_type' => $listingId === null ? null : 'listing',
            'entity_id' => $listingId,
            'payload' => null,
            'context' => null,
        ]);
    }
}
FILE,

'tests/Feature/Engagement/ListingReportTest.php' => <<<'FILE'
<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Engagement\Models\ListingReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\SeedsMarketplace;
use Tests\TestCase;

/**
 * الإبلاغ — الطرف المفقود من دورة الإشراف.
 *
 * لوحة التحكم تقرأ listing_reports وتفصل فيه، ولا شيء كان يكتب فيه.
 * هذه الاختبارات تحرس الاتجاه الآخر: أن الكتابة تحدث، وبشروطها.
 */
class ListingReportTest extends TestCase
{
    use RefreshDatabase, SeedsMarketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
        RateLimiter::clear('report:1');
    }

    public function test_guest_cannot_report(): void
    {
        $listing = $this->listing();

        $this->post(route('listing.report', $listing), ['reason' => 'fraud'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('listing_reports', 0);
    }

    public function test_a_member_can_report_a_listing(): void
    {
        $listing = $this->listing();
        $reporter = User::factory()->create();

        $this->actingAs($reporter)
            ->post(route('listing.report', $listing), [
                'reason' => 'fraud',
                'details' => 'يطلب تحويلاً مقدماً قبل رؤية السلعة.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('listing_reports', [
            'listing_id' => $listing->id,
            'reporter_id' => $reporter->id,
            'reason' => 'fraud',
            'status' => 'open',
        ]);
    }

    /** المالك لا يبلّغ على نفسه — وإلا صار الإبلاغ طريقاً لسحب إعلانه بالإشراف */
    public function test_owner_cannot_report_their_own_listing(): void
    {
        $listing = $this->listing();

        $this->actingAs($listing->user)
            ->post(route('listing.report', $listing), ['reason' => 'spam'])
            ->assertRedirect();

        $this->assertDatabaseCount('listing_reports', 0);
    }

    public function test_a_second_open_report_on_the_same_listing_is_refused(): void
    {
        $listing = $this->listing();
        $reporter = User::factory()->create();

        foreach ([1, 2] as $attempt) {
            $this->actingAs($reporter)
                ->post(route('listing.report', $listing), ['reason' => 'spam']);
        }

        $this->assertDatabaseCount('listing_reports', 1);
    }

    /**
     * وبعد الفصل في الأول يجوز بلاغ جديد.
     *
     * وهذا سبب رفض الفهرس الفريد على (listing_id, reporter_id): الحالة
     * مشروعة — أعاد المعلن نشر نفس المخالفة بعد أن صُرِف البلاغ.
     */
    public function test_a_new_report_is_allowed_after_the_first_was_closed(): void
    {
        $listing = $this->listing();
        $reporter = User::factory()->create();

        $this->actingAs($reporter)->post(route('listing.report', $listing), ['reason' => 'spam']);

        ListingReport::query()->update(['status' => 'dismissed']);

        $this->actingAs($reporter)->post(route('listing.report', $listing), ['reason' => 'fraud']);

        $this->assertDatabaseCount('listing_reports', 2);
    }

    public function test_other_requires_an_explanation(): void
    {
        $listing = $this->listing();

        $this->actingAs(User::factory()->create())
            ->from(route('listing.show', $listing))
            ->post(route('listing.report', $listing), ['reason' => 'other'])
            ->assertSessionHasErrors('details');

        $this->assertDatabaseCount('listing_reports', 0);
    }

    /**
     * الحدّ الساعي: الهجوم الحقيقي ليس تكرار البلاغ على إعلان واحد، بل
     * حساب واحد يبلّغ على خمسين إعلاناً لمنافس فيُغرق الطابور.
     */
    public function test_a_burst_of_reports_is_rate_limited(): void
    {
        $reporter = User::factory()->create();
        RateLimiter::clear('report:' . $reporter->id);

        $listings = collect(range(1, 6))->map(fn (): object => $this->listing());

        foreach ($listings as $listing) {
            $this->actingAs($reporter)->post(route('listing.report', $listing), ['reason' => 'spam']);
        }

        $this->assertSame(5, ListingReport::query()->count());
    }
}
FILE,

'tests/Feature/Analytics/BehaviourRollupTest.php' => <<<'FILE'
<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Console\ComputeAffinityCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SeedsMarketplace;
use Tests\TestCase;

/**
 * الأرقام التي كانت أصفاراً ثابتة.
 *
 * كل اختبار هنا يحرس عموداً كان يُكتب بقيمة مُختلقة (صفر) بتعليق يقول
 * «غير مبني بعد». وقد بُني — والاختبار هو ما يمنع رجوعه صفراً بصمت.
 */
class BehaviourRollupTest extends TestCase
{
    use RefreshDatabase, SeedsMarketplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMarketplace();
    }

    public function test_rollup_counts_favorites_instead_of_writing_zero(): void
    {
        $listing = $this->listing();
        $today = Carbon::today()->toDateString();

        $this->recordRawEvent('listing_viewed', null, $listing->id, $today);
        $this->recordRawEvent('favorite_added', User::factory()->create(), $listing->id, $today);
        $this->recordRawEvent('favorite_added', User::factory()->create(), $listing->id, $today);

        $this->artisan('analytics:rollup', ['--date' => $today])->assertSuccessful();

        $row = DB::table('listing_daily_stats')
            ->where('listing_id', $listing->id)
            ->where('stat_date', $today)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->favorites);
    }

    public function test_affinity_weights_a_contact_above_a_view(): void
    {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();

        $viewed = $this->listing();
        $contacted = $this->listing();

        // تصنيفان مختلفان مطلوبان ليكون للمقارنة معنى
        if ($viewed->category_id === $contacted->category_id) {
            $other = DB::table('categories')
                ->whereNotNull('parent_id')
                ->where('id', '!=', $viewed->category_id)
                ->value('id');

            $contacted->forceFill(['category_id' => $other])->save();
        }

        $this->recordRawEvent('listing_viewed', $user, $viewed->id, $today);
        $this->recordRawEvent('contact_revealed', $user, $contacted->id, $today);

        $this->artisan('analytics:affinity')->assertSuccessful();

        $viewScore = (float) DB::table('user_category_affinity')
            ->where('user_id', $user->id)->where('category_id', $viewed->category_id)->value('score');

        $contactScore = (float) DB::table('user_category_affinity')
            ->where('user_id', $user->id)->where('category_id', $contacted->category_id)->value('score');

        $this->assertGreaterThan($viewScore, $contactScore);
    }

    /**
     * التلاشي: نفس الحدث بنفس العدد، والأقدم يزن أقل. بلا هذا الاختبار
     * يمكن أن يُحذف التلاشي بلا أن يفشل شيء — ويبقى اهتمام العام الماضي
     * أعلى من اهتمام اليوم.
     */
    public function test_older_behaviour_decays(): void
    {
        $recentUser = User::factory()->create();
        $oldUser = User::factory()->create();

        $listing = $this->listing();

        $this->recordRawEvent('listing_viewed', $recentUser, $listing->id, Carbon::today()->toDateString());
        $this->recordRawEvent(
            'listing_viewed',
            $oldUser,
            $listing->id,
            Carbon::today()->subDays(ComputeAffinityCommand::HALF_LIFE_DAYS)->toDateString()
        );

        $this->artisan('analytics:affinity')->assertSuccessful();

        $recent = (float) DB::table('user_category_affinity')->where('user_id', $recentUser->id)->value('score');
        $old = (float) DB::table('user_category_affinity')->where('user_id', $oldUser->id)->value('score');

        $this->assertGreaterThan($old, $recent);
        $this->assertEqualsWithDelta($recent / 2, $old, 0.05);
    }

    /** الجدول صورةُ حال لا أرشيف: صفّ لمستخدم خرج من النافذة يختفي */
    public function test_affinity_replaces_stale_rows(): void
    {
        $user = User::factory()->create();
        $listing = $this->listing();

        DB::table('user_category_affinity')->insert([
            'user_id' => $user->id,
            'category_id' => $listing->category_id,
            'score' => 999,
            'last_event_at' => now()->subYear(),
            'computed_at' => now()->subYear(),
        ]);

        $this->recordRawEvent('listing_viewed', $user, $listing->id, Carbon::today()->toDateString());

        $this->artisan('analytics:affinity')->assertSuccessful();

        $score = (float) DB::table('user_category_affinity')
            ->where('user_id', $user->id)->value('score');

        $this->assertLessThan(999, $score);
        $this->assertSame(1, DB::table('user_category_affinity')->count());
    }
}
FILE,

];

// ═══════════════════════════════ الكتابة ═══════════════════════════════

$written = 0;
$replaced = 0;

foreach ($files as $relative => $contents) {
    $path = $root . '/' . $relative;
    $dir = dirname($path);

    if (! is_dir($dir) && ! mkdir($dir, 0o775, true) && ! is_dir($dir)) {
        fwrite(STDERR, "تعذّر إنشاء المجلد: {$dir}\n");
        exit(1);
    }

    $exists = file_exists($path);
    $normalized = str_replace("\r\n", "\n", rtrim($contents, "\n")) . "\n";

    if (file_put_contents($path, $normalized) === false) {
        fwrite(STDERR, "تعذّرت الكتابة: {$relative}\n");
        exit(1);
    }

    $exists ? $replaced++ : $written++;

    echo ($exists ? '~ ' : '+ ') . $relative . "\n";
}

echo "\n{$written} ملفاً جديداً، {$replaced} مستبدلاً.\n";

// ═══════════════════════════════ المسارات ═══════════════════════════════

$routes = $root . '/routes/web.php';
$requireLine = "require __DIR__ . '/../app/Modules/Engagement/Routes/reports.php';";

if (! is_file($routes)) {
    echo "! أضف يدوياً إلى routes/web.php: {$requireLine}\n";
} elseif (! str_contains((string) file_get_contents($routes), 'Engagement/Routes/reports.php')) {
    file_put_contents($routes, "\n// الإبلاغ عن إعلان\n{$requireLine}\n", FILE_APPEND);
    echo "~ routes/web.php (أُضيف سطر require)\n";
} else {
    echo "= routes/web.php (السطر موجود)\n";
}

// ═══════════════════════════════ نقل الاختبار في غير موضعه ═══════════════════════════════

/*
 * app/Modules/Shared/Services/ExchangeRateServiceTest.php يعلن
 * namespace Tests\Feature\Shared وهو داخل app/. النتيجة: PHPUnit لا
 * يراه فلا يُشغَّل أبداً — أي أن اختبارات خدمة أسعار الصرف كلها ساكتة
 * لا ناجحة. والملف يُشحن مع كود الإنتاج فوق ذلك.
 *
 * يُنقل بـrename لا بإعادة كتابة: محتواه صحيح، والنقل لا يخطئ في حرف.
 */
$misplaced = $root . '/app/Modules/Shared/Services/ExchangeRateServiceTest.php';
$correct = $root . '/tests/Feature/Shared/ExchangeRateServiceTest.php';

if (is_file($misplaced)) {
    if (! is_dir(dirname($correct))) {
        mkdir(dirname($correct), 0o775, true);
    }

    if (is_file($correct)) {
        echo "! الملفان موجودان — راجع {$correct} ثم احذف النسخة في app/ يدوياً\n";
    } elseif (rename($misplaced, $correct)) {
        echo "→ نُقل ExchangeRateServiceTest.php إلى tests/Feature/Shared/\n";
    } else {
        echo "! تعذّر النقل — انقله يدوياً\n";
    }
} else {
    echo "= ExchangeRateServiceTest.php ليس في app/ (منقول سابقاً)\n";
}

// ═══════════════════════════════ تنظيف الشجرة ═══════════════════════════════

/*
 * ملفات .bak نتيجة محاولات ترقيع سابقة. خطرها ليس الفوضى: ملف
 * ListingController.php.bak يحمل نسخة قديمة من نفس الصنف، فمن يقرأ
 * المستودع (أو يفتح مجلد البحث في المحرر) يقرأ أحياناً الملف الخطأ
 * ويصلح عيباً في نسخة لا تُنفَّذ. والمستودع العام هو ما سيراه من يقرأ
 * سيرتك.
 */
$junk = 0;

$bakFiles = glob($root . '/app/Modules/*/*.bak') ?: [];
foreach (['*/*/*.bak', '*/*/*/*.bak', '*/*/*/*/*.bak', '*/*/*/*/*/*.bak'] as $pattern) {
    $bakFiles = array_merge($bakFiles, glob($root . '/app/Modules/' . $pattern) ?: []);
}

foreach (array_unique($bakFiles) as $file) {
    if (unlink($file)) {
        $junk++;
    }
}

echo "− حُذف {$junk} ملف .bak\n";

// مجلد باسم "SearchQueryBuilder.php app" — ناتج أمر shell مقطوع
$broken = $root . '/app/Modules/Search/Services/SearchQueryBuilder.php app';

if (is_dir($broken)) {
    @rmdir($broken);
    echo is_dir($broken)
        ? "! المجلد المشوّه غير فارغ: {$broken} — راجعه يدوياً\n"
        : "− حُذف مجلد باسم مشوّه في Search/Services\n";
}

$gitignore = $root . '/.gitignore';

if (is_file($gitignore) && ! str_contains((string) file_get_contents($gitignore), '*.bak')) {
    file_put_contents($gitignore, "\n# نسخ احتياطية من محاولات تعديل\n*.bak\n", FILE_APPEND);
    echo "~ .gitignore (أُضيف *.bak)\n";
}

// ═══════════════════════════════ الخطوات التالية ═══════════════════════════════

echo <<<'NEXT'

الخطوات:

  1. php artisan optimize:clear
  2. php artisan test
  3. php artisan analytics:rollup --days=7
  4. php artisan analytics:affinity
  5. افتح إعلاناً بحساب غير مالكه → «الإبلاغ عن هذا الإعلان»،
     ثم /admin/reports لتراه في الطابور.

عمودان يبقيان صفراً بصدق، ولا يُلمسان في هذه الدفعة:

  · filter_usage_stats.times_removed_fast — يحتاج قياساً في المتصفح
    (وقت التطبيق ثم حدث عند الإلغاء خلال عشر ثوانٍ). التعليق في
    RollupCommand يقول ذلك أصلاً، وهذا أصدق من رقم مخترع.
  · funnel_daily.forms_abandoned — يحتاج حدثاً من نموذج الإنشاء عند
    المغادرة بلا إرسال (beacon). نفس السبب.

وثغرة معماريّة واحدة تحتاج قرارك: الحساب المحظور تبقى جلسته المفتوحة
عاملة حتى تنتهي. القطع يحتاج وسيطاً على مجموعة web كلها — تعديل في
bootstrap/app.php خارج أي وحدة.

NEXT;