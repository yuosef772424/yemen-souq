<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول رموز الوصول — سكيمة Sanctum، مكتوبةً هنا لا منشورةً هناك.
 *
 * ═══ لماذا داخل الوحدة ═══
 *
 * `vendor:publish --tag=sanctum-migrations` يضعها في
 * `database/migrations`. وعقد الوحدات يقول إن مجلداً تحت `app/Modules`
 * يعمل بمجرد وجوده — وجدولٌ يخصّ وحدة Api في مجلد عام يكسر الاتجاه
 * الآخر: حذف الوحدة يترك جدولاً لا مالك له.
 *
 * والسكيمة مطابقة لما ينشره Sanctum 4 حرفياً — أسماء الأعمدة عقدٌ مع
 * `PersonalAccessToken` في الحزمة لا اختيارٌ لنا. من عدّلها عدّل ما
 * تقرؤه الحزمة.
 *
 * **تحذير:** لا تنشر هجرة Sanctum بعد اليوم — تشغيلها مع هذه يُنتج
 * محاولةَ إنشاءٍ لجدول قائم.
 *
 * ═══ token بطول 64 وفريد ═══
 *
 * العمود يحمل **تجزئة** الرمز (sha256 بستّ وخمسين محرفاً في الست
 * عشري)، لا الرمز. النص الصريح يُعرض مرة واحدة عند الإصدار ولا يُخزَّن —
 * فمن يقرأ الجدول لا يحصل على مفاتيح. نفس مبدأ `code_hash` في
 * `phone_verifications`.
 *
 * ═══ expires_at مفهرس ═══
 *
 * `TokenIssuer` يحذف المنتهية قبل كل إصدار، و`sanctum:prune-expired`
 * ينظّف دورياً. كلاهما يستعلم بهذا العمود على جدول ينمو بعدد
 * (مستخدمين × أجهزة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();

            // morphs لا foreignId: العقد في الحزمة متعدّد الأشكال، وهو
            // ما يسمح بإصدار رمز لكيان غير المستخدم لاحقاً بلا هجرة
            $table->morphs('tokenable');

            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
