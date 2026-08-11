<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * متى بِيع، لا أنه بِيع فقط.
 *
 * status = 'sold' يقول الحقيقة الحالية ولا يحمل زمناً. وبلا الزمن لا
 * يُعرف كم بقي الإعلان معروضاً قبل بيعه — وهو المؤشّر الوحيد الذي يربط
 * السعر والتصنيف والموقع بسرعة البيع، وأول ما يُسأل عنه عند تسعير إعلان
 * جديد.
 *
 * وupdated_at لا يصلح بديلاً: أي تعديل لاحق يدهسه.
 *
 * والحراسة بـhasColumn لأن الهجرات السابقة نُفِّذت على قاعدة التطوير،
 * وقد تُشغَّل هذه على قاعدة أُعيد بناؤها من الصفر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (! Schema::hasColumn('listings', 'sold_at')) {
                $table->timestamp('sold_at')->nullable()->after('expires_at')
                    ->comment('وقت تعليمه مباعاً — يُفرَّغ عند التراجع');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('sold_at');
        });
    }
};
