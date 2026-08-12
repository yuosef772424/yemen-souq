<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * تُسقط `password_reset_tokens` لمن هاجر قاعدته قبل حذفها من هجرة
 * لارافل الأصلية.
 *
 * الاستعادة في هذا المشروع بالجوال، ولا سطر يلمس هذا الجدول. وأُسقط
 * معه مفتاح `passwords` من config/auth.php — انظر
 * docs/architecture/removed-schema.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        // بلا تراجع — التعريف في docs/architecture/removed-schema.md
    }
};
