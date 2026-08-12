<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طابور الرسائل الصادرة — الجدول الذي يقرؤه هاتف تيرمكس.
 *
 * ═══ لماذا جدول لا طابور لارافل ═══
 *
 * `jobs` طابورٌ يستهلكه عاملٌ **يملك الكود**. والمستهلك هنا سكربت shell
 * على هاتف أندرويد، لا يعرف PHP ولا التسلسل الثنائي الذي يكتبه لارافل
 * في `payload`. وجدولٌ بأعمدة صريحة يُقرأ بـ`curl` و`jq` في ثلاثة أسطر.
 *
 * والأهم: الطابور يمحو المهمة عند نجاحها، وهذا الجدول **سجلّ تسليم**.
 * «لماذا لم تصلني الرسالة؟» سؤالٌ يُطرح بعد ساعة، وجوابه صفٌّ يقول
 * متى سُحبت ومن سحبها وكم محاولة وما الخطأ.
 *
 * ═══ الوحدة قابلة للحذف، وهذا الجدول جزء منها ═══
 *
 * الهجرة داخل مجلد الوحدة لا في `database/migrations`: حذف
 * `app/Modules/Sms` يأخذ تعريف الجدول معه، فلا يبقى جدولٌ بلا مالك.
 * (والحذف الفعلي للجدول يحتاج `migrate:rollback` قبل حذف المجلد —
 * انظر docs/architecture/sms-outbox.md.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->id();

            /**
             * الرقم بصيغة E.164 كما يخزّنه `users.phone`.
             *
             * والتطبيع مسؤولية من يُدخل لا هذا الجدول: رقمٌ بصيغتين
             * يعني رسالتين لشخص واحد، وسجلّ تسليم لا يُجمَّع.
             */
            $table->string('to', 20);

            /**
             * nullable لأن `redact_body_on_send` يمحوه بعد التسليم —
             * وهو يحمل رمز تحقق صريحاً. انظر config/sms.php.
             */
            $table->text('body')->nullable();

            /** لماذا أُرسلت — للتشخيص والإحصاء، لا للتفريع في الكود */
            $table->string('purpose', 40)->default('general');

            $table->string('status', 12)->default('queued');

            /**
             * العدّاد يزيد عند **السحب** لا عند الفشل.
             *
             * هاتفٌ يُقتل بعد السحب وقبل التأكيد لا يؤكّد شيئاً — فلو كان
             * العدّاد على الفشل لبقيت الرسالة تدور بين الطابور والسحب
             * إلى الأبد. الزيادة عند السحب تجعل كل محاولةٍ محسوبة، ناجحة
             * كانت أو ميتة.
             */
            $table->unsignedTinyInteger('attempts')->default(0);

            /**
             * رمز السحبة — وهو ما يجعل التأكيد آمناً.
             *
             * بلاه يستطيع هاتفٌ استُعيدت رسالته (لأن سحبته انتهت) أن
             * يؤكّد إرسالها **بعد** أن سحبها هاتفٌ آخر وأرسلها — فيُكتب
             * تأكيدٌ على سحبةٍ ليست له. والرمز يجعل التأكيد مقروناً
             * بالسحبة التي أنتجته لا بالرسالة وحدها.
             */
            $table->uuid('claim_token')->nullable();
            $table->string('claimed_by', 60)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error', 255)->nullable();

            /** بعده الرسالة بلا قيمة — الرمز الذي تحمله ميت */
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            /**
             * الفهرس الذي يخدم السحب: أقدم `queued` لم ينتهِ أجلها.
             *
             * الترتيب (status, expires_at, id) يطابق ترتيب الشروط في
             * `scopeClaimable` — والعامل ينادي هذا الاستعلام كل بضع
             * ثوانٍ على جدول ينمو بعدد كل رسالة أُرسلت في عمر المنصة.
             */
            $table->index(['status', 'expires_at', 'id'], 'sms_claim_idx');

            /** استعادة السحبات الميتة — الاستعلام الثاني في كل نداء */
            $table->index(['status', 'claim_expires_at'], 'sms_reap_idx');

            /** التنظيف الدوري */
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
    }
};
