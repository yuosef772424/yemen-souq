<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأسئلة العامة على الإعلان.
 *
 * ═══ لماذا جدول جديد ولم تكفِ reviews ═══
 *
 * لأنهما شيئان مختلفان تماماً. reviews يقيّم *البائع* بعد تعامل، والسؤال
 * العام معلومة عن *السلعة* تفيد كل مشترٍ لاحق. خلطهما خطأ تصنيفي يُنتج
 * جدولاً لا يخدم أياً منهما.
 *
 * ═══ ولماذا سؤال عام لا رسالة خاصة ═══
 *
 * هذه أقوى حجة في الميزة كلها: عشرة مشترين يسألون «هل الفحص مسموح؟» في
 * عشر محادثات خاصة يعني عشر إجابات من البائع. سؤال عام واحد وجواب واحد
 * يعني صفر تكرار — والعاشر يقرأ الجواب بلا أن يسأل. الأسئلة العامة
 * تخفّف الرسائل لا تزيدها.
 *
 * ═══ الجواب في نفس الصف لا في جدول ردود ═══
 *
 * سؤال وجواب، لا نقاش. جدول ردود يفتح الباب لسلسلة تعليقات عامة على
 * الإعلان — وهي مساحة تُدار وتُراقَب ولا تخدم بيع سلعة. القيد البنيوي
 * أرخص من قواعد إشراف لاحقة.
 *
 * ═══ المالك لا يخفي سؤالاً ولا يحذفه ═══
 *
 * وهذا قرار منتج لا تفصيل تقني. لو استطاع المالك حذف «هل هي مصدومة؟»
 * لصارت الأسئلة العامة لوحة دعاية لا مصدر معلومة، وفقد المشتري السبب
 * الوحيد لقراءتها. الحذف للسائل وحده، والإخفاء للإدارة عند الإساءة —
 * ولهذا hidden_at وhidden_reason هنا من الآن، تنتظر لوحة الإدارة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('asker_id')->constrained('users')->cascadeOnDelete();

            $table->string('body', 400);

            // جواب واحد من المالك — answered_at يفرّق «لم يُجَب» عن «جواب فارغ»
            $table->string('answer', 800)->nullable();
            $table->timestamp('answered_at')->nullable();

            // للإدارة وحدها — انظر تعليق الملف
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason', 160)->nullable();

            $table->timestamps();

            $table->index(['listing_id', 'created_at']);
            $table->index(['asker_id', 'created_at']);

            // المالك يحتاج قائمة «أسئلة تنتظر جوابي» عبر إعلاناته كلها
            $table->index(['answered_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_questions');
    }
};