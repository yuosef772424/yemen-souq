<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `categories.allows_auction` — مفتاحٌ في لوحة الإدارة لا يفتح شيئاً.
 *
 * المزادات محذوفة، والعمود كان يسبقها. وبقاؤه أسوأ من غيابه: مربّع
 * اختيار في شاشة التصنيفات يُقرأ كإعداد عامل، فيؤشّره المدير وينتظر
 * أثراً لا يقع.
 *
 * `hasColumn` لأن الهجرة الأصلية لم تعد تُنشئه — فالتثبيت الجديد يمرّ
 * من هنا بلا عمل، والقاعدة القديمة تُنظَّف.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'allows_auction')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('allows_auction');
        });
    }

    public function down(): void
    {
        // بلا تراجع — التعريف في docs/architecture/removed-schema.md
    }
};
