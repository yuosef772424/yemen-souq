<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الكتابة في user_events يجب أن تكون رخيصة قدر الإمكان.
 * التحليل يتم على الجداول المجمّعة أدناه، لا على السجل الخام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_name', 60)->comment('search_performed, listing_viewed, contact_revealed ...');
            $table->timestamp('occurred_at', 3);
            $table->unsignedBigInteger('user_id')->nullable()->comment('فارغ للزائر غير المسجل');
            $table->uuid('anon_id')->comment('معرّف مجهول ثابت — يُربط بالحساب عند التسجيل');
            $table->uuid('session_id');
            $table->string('entity_type', 30)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload')->nullable()->comment('ممنوع وضع أي بيانات شخصية هنا');
            $table->json('context')->nullable()->comment('منصة، عرض الشاشة، مصدر الزيارة');

            $table->index(['event_name', 'occurred_at']);
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
            $table->index(['session_id', 'occurred_at']);
            $table->index(['anon_id', 'occurred_at']);

            // بلا مفاتيح أجنبية عمداً: الكتابة يجب أن تكون أسرع ما يمكن،
            // والسجل يُحذف بعد 90 يوماً بينما يبقى المستخدم.
        });

        Schema::create('user_tracking_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->boolean('allow_analytics')->default(true);
            $table->boolean('allow_personalization')->default(true);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('listing_daily_stats', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->date('stat_date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('unique_viewers')->default(0);
            $table->unsignedInteger('favorites')->default(0);
            $table->unsignedInteger('contacts')->default(0);
            $table->unsignedInteger('search_impressions')->default(0)->comment('ظهر في نتائج بحث');
            $table->unsignedInteger('search_clicks')->default(0);
            $table->decimal('avg_dwell_sec', 8, 2)->nullable();

            $table->primary(['listing_id', 'stat_date']);
            $table->index('stat_date');
        });

        Schema::create('search_query_stats', function (Blueprint $table) {
            $table->id();
            $table->string('query_normalized', 180);
            $table->date('stat_date');
            $table->unsignedInteger('times_searched')->default(0);
            $table->decimal('avg_results', 10, 2)->default(0);
            $table->unsignedInteger('zero_result_count')->default(0)
                ->comment('كل رقم هنا عيب محتمل في البحث');
            $table->unsignedInteger('click_count')->default(0);
            $table->decimal('avg_click_position', 6, 2)->nullable();
            $table->unsignedInteger('avg_duration_ms')->nullable();

            $table->unique(['query_normalized', 'stat_date']);
            $table->index(['stat_date', 'zero_result_count']);
            $table->index(['stat_date', 'times_searched']);
        });

        Schema::create('filter_usage_stats', function (Blueprint $table) {
            $table->id();
            $table->string('filter_key', 60)->comment('price, currency, category, condition ...');
            $table->date('stat_date');
            $table->unsignedInteger('times_applied')->default(0);
            $table->unsignedInteger('times_removed_fast')->default(0)
                ->comment('أُلغي خلال 10 ثوانٍ — إشارة إرباك');
            $table->decimal('avg_results_after', 10, 2)->nullable();
            $table->unsignedInteger('zero_after_count')->default(0);

            $table->unique(['filter_key', 'stat_date']);
            $table->index(['stat_date', 'times_removed_fast']);
        });

        Schema::create('user_category_affinity', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->decimal('score', 10, 4)->default(0)
                ->comment('مشاهدة=1، مفضلة=5، تواصل=10، مع تلاشٍ زمني');
            $table->timestamp('last_event_at')->nullable();
            $table->dateTime('computed_at');

            $table->primary(['user_id', 'category_id']);
            $table->index(['user_id', 'score']);
        });

        Schema::create('funnel_daily', function (Blueprint $table) {
            $table->date('stat_date')->primary();
            $table->unsignedInteger('searches')->default(0);
            $table->unsignedInteger('searches_with_click')->default(0);
            $table->unsignedInteger('listing_views')->default(0);
            $table->unsignedInteger('contacts_revealed')->default(0);
            $table->unsignedInteger('listings_created')->default(0);
            $table->unsignedInteger('forms_abandoned')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_daily');
        Schema::dropIfExists('user_category_affinity');
        Schema::dropIfExists('filter_usage_stats');
        Schema::dropIfExists('search_query_stats');
        Schema::dropIfExists('listing_daily_stats');
        Schema::dropIfExists('user_tracking_preferences');
        Schema::dropIfExists('user_events');
    }
};
