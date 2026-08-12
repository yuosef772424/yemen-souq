<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفتاح الطيّ — العمود الذي يمنع القائمة من أن تصير سجلّ رسائل.
 *
 * بلاه، محادثة فيها أربعون رسالة غير مقروءة تُنتج أربعين صفاً في
 * app_notifications، فتُدفن كل بقية الإشعارات تحتها. ومع المفتاح تُطوى
 * الأربعون في صفٍّ واحد يُحدَّث نصُّه وتاريخه.
 *
 * لماذا عمود لا اشتقاق من (type, data): البحث عن صفّ سابق يجب أن يكون
 * استعلاماً مفهرساً على مساواة نصّية. الاشتقاق من عمود json يعني مسحاً
 * لكل إشعارات المستخدم عند كل رسالة.
 *
 * والفهرس على (user_id, dedupe_key) لا يضمّ read_at بقصد: الطيّ لا يقع
 * إلا على غير المقروء، وعدد صفوف المستخدم لمفتاح واحد لا يتجاوز اثنين
 * أو ثلاثة — فتصفية read_at بعد الفهرس أرخص من حملها فيه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->string('dedupe_key', 120)->nullable()->after('type');

            $table->index(['user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
