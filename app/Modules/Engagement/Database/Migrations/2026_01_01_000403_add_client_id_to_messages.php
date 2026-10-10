<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * معرّف يولّده العميل لكل رسالة، فتصير إعادة الإرسال آمنة.
 *
 * ═══ لماذا فهرس فريد لا كاش زمني ═══
 *
 * كاش «رسالة بهذا النص في آخر دقيقة» يخمّن النية: يمنع مستخدماً من أن
 * يكتب «تمام» مرتين عمداً، ويُسقط الحماية عند انقضاء النافذة، ولا
 * يصمد أمام طلبين متزامنين يمرّان معاً قبل أن يكتب أحدهما. الفهرس الفريد
 * يضمن في المخزن نفسه أن (محادثة، مرسل، معرّف) يُكتب مرة واحدة مهما
 * تسابقت الطلبات — والخدمة تلتقط مخالفته وتعيد الرسالة الموجودة.
 *
 * ═══ لماذا nullable ═══
 *
 * الصفوف القديمة بلا معرّف، والعملاء القدامى (تطبيق لم يُحدَّث) لا
 * يرسلونه. والقيمة الفارغة لا تتعارض في الفهرس الفريد في SQLite ولا
 * MySQL، فتبقى رسائلهم تُنشأ كما كانت.
 *
 * النطاق (conversation_id, sender_id) لا المعرّف وحده: معرّفان متطابقان
 * من مستخدمين مختلفين ليسا تكراراً، ولا يجوز أن يستطيع مستخدم حجب معرّف
 * عن غيره أو قراءة رسالة غيره بتخمينه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('client_id', 64)->nullable()->after('body');

            $table->unique(['conversation_id', 'sender_id', 'client_id'], 'messages_client_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_client_id_unique');
            $table->dropColumn('client_id');
        });
    }
};
