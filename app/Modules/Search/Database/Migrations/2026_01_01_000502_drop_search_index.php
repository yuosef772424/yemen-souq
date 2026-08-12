<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * تُسقط `search_index` لمن هاجر قاعدته قبل حذفها من الهجرة الأصلية.
 *
 * الجدول كان 96 ك.ب فهارس على صفر صفّ. والبديل قائم: `search_blob` على
 * `listings` وفهرسه FULLTEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('search_index');
    }

    public function down(): void
    {
        // بلا تراجع — التعريف في docs/architecture/removed-schema.md
    }
};
