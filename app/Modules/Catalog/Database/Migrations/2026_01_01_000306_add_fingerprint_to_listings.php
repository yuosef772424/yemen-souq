<?php

use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بصمة محتوى الإعلان — العمود الذي يجعل كشف التكرار مطابقةً واحدة.
 *
 * الفهرس `(user_id, fingerprint)` لا `fingerprint` وحده: السؤال دائماً
 * «هل لهذا المعلن إعلانٌ بهذا المحتوى» — وإعلانان متطابقان لمعلنَين
 * مختلفين ليسا تكراراً بل سلعتان متشابهتان في سوق.
 *
 * وليس فريداً بقصد: القيد الفريد يشمل المحذوف منطقياً والمنتهي، فيمنع
 * معلناً حذف إعلانه من إعادة نشره. الشرط على الحيّ وحده، وموضعه
 * `DuplicateListingGuard`.
 *
 * ═══ والعمود nullable ═══
 *
 * الصفوف القائمة تُملأ أدناه، لكن `null` يبقى ممكناً لصفٍّ كُتب بمسار
 * يتخطّى الـObserver (`DB::table()->insert`). والحارس يقارن بقيمة
 * محسوبة لا بـ`null`، فصفٌّ بلا بصمة لا يطابق شيئاً — يمرّ ولا يمنع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->char('fingerprint', 64)->nullable()->after('description_normalized')
                ->comment('sha256 لمحتوى الإعلان — لكشف التكرار');

            $table->index(['user_id', 'fingerprint'], 'idx_listing_fingerprint');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('idx_listing_fingerprint');
            $table->dropColumn('fingerprint');
        });
    }

    /**
     * الصفوف القائمة.
     *
     * بلاها لا يرى الحارس شيئاً مما نُشر قبل اليوم، فيمرّ التكرار على
     * كل إعلان قديم. وبـ`DB::table` لا `$listing->save()`: الحفظ يوقظ
     * الـObserver فيعيد حساب السعر والنصّ المجمَّع لثلاثمئة صفّ بلا سبب.
     */
    private function backfill(): void
    {
        $fingerprint = app(ListingFingerprint::class);

        Listing::withTrashed()
            ->select(['id', 'title', 'description', 'category_id', 'location_id',
                'price_type', 'price_amount', 'currency_code', 'condition_type'])
            ->chunkById(500, function ($listings) use ($fingerprint): void {
                foreach ($listings as $listing) {
                    DB::table('listings')
                        ->where('id', $listing->id)
                        ->update(['fingerprint' => $fingerprint->for($listing)]);
                }
            });
    }
};
