<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تجديد بلا أثر تجديد غير قابل للمراجعة.
 *
 * renewed_at يجيب: هل جُدِّد هذا الإعلان فعلاً أم أن expires_at
 * البعيد جاء من الإنشاء؟ وrenewals_count يجيب: كم مرة؟ — وهو ما
 * يكشف الإعلان المُعاد تجديده منذ سنة ولم يُبَع، وهي إشارة تسعير
 * لا حالة خطأ.
 *
 * الحراسة بـhasColumn لأن الهجرات السابقة نُفِّذت بالفعل على قاعدة
 * التطوير، وقد تُشغَّل هذه على قاعدة أُعيد بناؤها من الصفر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (! Schema::hasColumn('listings', 'renewed_at')) {
                $table->timestamp('renewed_at')->nullable()->after('published_at')
                    ->comment('آخر تجديد — منفصل عن published_at بقصد');
            }

            if (! Schema::hasColumn('listings', 'renewals_count')) {
                $table->unsignedSmallInteger('renewals_count')->default(0)->after('renewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['renewed_at', 'renewals_count']);
        });
    }
};