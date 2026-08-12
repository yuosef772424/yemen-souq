<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `listings.sale_type` — عمودٌ بقيمتين، إحداهما محذوفة.
 *
 * `auction` لم يكتبها مسار واحد: كل من ينشئ إعلاناً يمرّر `direct`
 * صراحةً. وبحذف جدولَي المزاد صار العمود سؤالاً بجوابٍ واحد — ومعه
 * فلترٌ في البحث (`?type=`) يَعِد بتقسيمٍ لا يقع.
 *
 * ولا يُترك «احتياطاً»: يوم تُبنى المزادات سيحمل الإعلان مزاده في
 * جدوله، والعمود يُعاد وقتها بشكله الصحيح لا بشكل خُمِّن قبل سنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('listings', 'sale_type')) {
            return;
        }

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('sale_type');
        });
    }

    public function down(): void
    {
        // بلا تراجع — التعريف في docs/architecture/removed-schema.md
    }
};
