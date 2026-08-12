<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قناة التوصيل وسلسلة التراجع.
 *
 * ═══ هجرة ثانية لا تعديل الأولى ═══
 *
 * الجدول موجود في قواعد قائمة. وتعديل الهجرة الأصلية يعمل على استنساخ
 * نظيف وحده — ويترك كل بيئة هاجرت أمس بجدولٍ بلا العمودين، بلا أن يشير
 * شيء إلى السبب.
 *
 * ═══ channel افتراضه sms ═══
 *
 * الصفوف القائمة كلها رسائل نصية، والافتراض يجعلها صحيحةً بلا تحديث
 * صريح — والفهرس أدناه يبقى قابلاً للاستعمال من أول يوم.
 *
 * ═══ failed_over_from: سلسلة لا علَم ═══
 *
 * البديل المرفوض: `is_failover: bool`. يقول «هذه محاولة ثانية» ولا يقول
 * عن أيّ رسالة — فلا يُعرف أنجحت القناة البديلة لرمزٍ فشلت فيه الأولى،
 * ولا كم مرة يتراجع رقمٌ بعينه. والمعرّف يجعل المسار كله مقروءاً بصفٍّ
 * واحد.
 *
 * ولا مفتاح أجنبي عليه: `sms:prune` يحذف الأصل بعد سبعة أيام وقد يبقى
 * الخلف — وقيدٌ يمنع ذلك يجعل التنظيف يفشل على صفوفٍ لا ضرر في بقاء
 * إشارتها معلَّقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->string('channel', 12)->default('sms')->after('purpose');
            $table->unsignedBigInteger('failed_over_from')->nullable()->after('attempts');
        });

        Schema::table('sms_messages', function (Blueprint $table): void {
            /**
             * الفهرس يشمل القناة الآن.
             *
             * السحب صار مشروطاً بها — وبلا القناة في الفهرس يمسح عاملُ
             * واتساب صفوفَ SMS في كل نداء ليتخطّاها. الفهرس القديم يُسقَط
             * لأنه صار بادئةً من هذا: إبقاؤه فهرسٌ يُحدَّث في كل كتابة
             * ولا يُقرأ.
             */
            $table->index(['channel', 'status', 'expires_at', 'id'], 'sms_channel_claim_idx');
        });

        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->dropIndex('sms_claim_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->index(['status', 'expires_at', 'id'], 'sms_claim_idx');
        });

        Schema::table('sms_messages', function (Blueprint $table): void {
            $table->dropIndex('sms_channel_claim_idx');
            $table->dropColumn(['channel', 'failed_over_from']);
        });
    }
};
