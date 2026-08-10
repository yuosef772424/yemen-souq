<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * غرفة انتظار المكافئات.
 *
 * ═══ لماذا جدول وسيط ولا كتابة مباشرة ═══
 *
 * المكافئ الخاطئ لا يُعطِّل البحث — يُفسده بهدوء. «شقة ↔ بيت» تبدو
 * صحيحة لغوياً وتجعل من يبحث عن شقة للإيجار يرى بيوتاً للبيع، و«وكالة ↔
 * جديد» تبدو صحيحة وتخلط سعرين مختلفين. وأي نموذج لغوي سيقترح الزوجين
 * بثقة تامة — وهذا بالضبط ما يجعل الاقتراح الآلي خطراً: ليس أنه سيّئ،
 * بل أنه حسن الظاهر بلا سند.
 *
 * فالمرشّح يحمل معه ما يسمح بالحكم عليه: من أين جاء، وعلى أي دليل، وبأي
 * ثقة، ولماذا. والترقية إلى الجدول الحيّ تمرّ ببوابة آلية
 * (SynonymGate) ثم بالمجموعة الذهبية.
 *
 * source:
 *   rule       قاعدة حتمية (نادر هنا — القواعد لا تحتاج جدولاً)
 *   behaviour  منقَّب من user_events — أقوى دليل: مستخدم حقيقي أعاد
 *              الصياغة أو نقر النتيجة نفسها من استعلامين
 *   ai         مقترح من نموذج لغوي — أدنى ثقة، أكثر تغطية
 *   manual     إدخال يدوي
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_synonym_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('term', 80)->comment('مطبّع');
            $table->string('synonym', 80)->comment('مطبّع');
            $table->enum('source', ['rule', 'behaviour', 'ai', 'manual']);

            $table->unsignedInteger('evidence_count')->default(0)
                ->comment('عدد الجلسات المستقلة التي شهدت الزوج — لا عدد الأحداث');
            $table->decimal('confidence', 4, 3)->default(0);
            $table->string('rationale', 255)->nullable()
                ->comment('لماذا اقتُرح — يُقرأ عند المراجعة');

            $table->enum('status', ['pending', 'approved', 'rejected', 'promoted'])
                ->default('pending');
            $table->string('decision_reason', 255)->nullable()
                ->comment('سبب الرفض أو مصدر القبول — لا يُترك فارغاً عند الرفض');

            $table->timestamp('created_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // الزوج مرة واحدة: مصدران يقترحان الشيء نفسه يزيدان الدليل
            // لا عدد الصفوف
            $table->unique(['term', 'synonym']);
            $table->index(['status', 'evidence_count']);
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonym_candidates');
    }
};