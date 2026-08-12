<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * تُسقط ما حُذف من هجرة الوحدة الأصلية، لمن هاجر قاعدته قبل الحذف.
 *
 * على قاعدةٍ جديدة لا تفعل شيئاً — الجداول لم تُنشأ أصلاً، و`dropIfExists`
 * هو ما يجعل المسارين يلتقيان عند نفس النتيجة.
 *
 * ولا `down()` يُعيدها: التراجع هنا يعني إعادة إنشاء جداول فارغة قُرِّر
 * حذفها، وتعريفها الكامل محفوظ في docs/architecture/removed-schema.md —
 * وهو الموضع الذي تُنسخ منه يوم تُبنى الميزة، لا هذه الهجرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // bids قبل auctions: المفتاح الأجنبي يتجه من الأول إلى الثاني
        Schema::dropIfExists('bids');
        Schema::dropIfExists('auctions');
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('reviews');
    }

    public function down(): void
    {
        // بلا تراجع — انظر أعلاه
    }
};
