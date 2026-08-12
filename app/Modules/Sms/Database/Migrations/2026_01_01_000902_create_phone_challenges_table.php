<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تحدّيات التوثيق العكسي — العميل يرسل إلينا.
 *
 * ═══ لماذا جدول مستقل لا إعادة استعمال phone_verifications ═══
 *
 * هذا أهمّ قرار في القناة الثالثة، وسببه أمني بحت.
 *
 * في التوثيق العادي **سرّية الرمز هي الإثبات**: من يعرفه يملك الهاتف.
 * وفي العكسي **مصدر الرسالة هو الإثبات**: الرمز معروضٌ على الشاشة
 * لصاحبه ليرسله، والشبكة هي من تكتب رقم المرسِل.
 *
 * ولو تشاركا نفس الجدول لانهار الأول: الرمز المعروض على الشاشة يُكتب في
 * نموذج `/verify-phone` فيُقبل — أي أن أيّ أحد يوثّق أيّ رقم بلا أن
 * يملكه. النموذجان يقرآن مخزنين مختلفين، فلا يقبل أحدهما رمز الآخر
 * إطلاقاً.
 *
 * وهذه بالضبط الحالة التي لا يكشفها اختبار: كلا المسارين يعمل، والثغرة
 * في التقائهما.
 *
 * ═══ user_id مع phone معاً ═══
 *
 * الرقم وحده لا يكفي: نحتاج معرفة أيّ حساب نوثّقه حين تصل الرسالة —
 * والرسالة لا تحمل جلسة. والحساب وحده لا يكفي: الإثبات هو أن المرسِل
 * يطابق الرقم المُدَّعى.
 *
 * ═══ والرمز مجزَّأ بـbcrypt لا sha256 ═══
 *
 * البحث بالرقم أولاً ثم فحص التجزئة — فلا حاجة إلى تجزئة قابلة للبحث.
 * ونفس مبدأ `phone_verifications`: من يقرأ الجدول لا يحصل على مفاتيح.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_challenges', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** الرقم المُدَّعى — يجب أن يطابق مرسِل الرسالة الواردة */
            $table->string('phone', 20);

            $table->string('token_hash');

            /**
             * المحاولات: رسائل واردة لهذا الرقم لم تطابق.
             *
             * السقف هنا يمنع من يقصف رقمنا برسائل يخمّن فيها رموز
             * الآخرين — والتكلفة عليه لا علينا، لكن العدّ يوقف التخمين.
             */
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            /** البحث يقع بالرقم — الرسالة الواردة لا تحمل غيره */
            $table->index(['phone', 'consumed_at', 'expires_at'], 'challenges_lookup_idx');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_challenges');
    }
};
