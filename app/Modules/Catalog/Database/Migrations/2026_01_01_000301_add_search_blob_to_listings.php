<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عمود نص واحد يجمع كل ما قد يكتبه الباحث: العنوان، الوصف، اسم التصنيف،
 * اسم المديرية، واسم المحافظة — كلها مطبّعة.
 *
 * لماذا العمود على listings لا على search_index: قاعدة اتجاه الاعتماد.
 * وحدة Search تقرأ ولا تكتب في جداول Catalog، والـObserver الذي يملأ
 * العمود يعيش في Catalog. جدول search_index المشتق يبقى للانتقال حين
 * يفرضه الحجم — لا نُقدّمه لأجل ميزة تسع عموداً واحداً.
 *
 * ولماذا عمود مكرَّر بدل JOIN على locations و categories وقت البحث:
 * الـJOIN مع LIKE على عمود مجلوب يمنع أي فهرس ويضاعف كلفة كل استعلام.
 * التكرار هنا مقصود — العمود مشتق ويُعاد بناؤه بأمر واحد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->text('search_blob')
                ->nullable()
                ->after('description_normalized')
                ->comment('عنوان + وصف + تصنيف + موقع، مطبّع — للمطابقة النصية فقط');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('search_blob');
        });
    }
};