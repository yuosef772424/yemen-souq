<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وحدة البحث لا تملك أي بيانات أصلية — والمرادفات وحدها ما تملكه.
 *
 * وحُذف منها `search_index`: نسخةٌ مسطّحة من `listings` لم يكتب فيها
 * سطر واحد، لأن `listings.search_blob` وفهرسه FULLTEXT يؤدّيان الغرض.
 * تعريفها كاملاً في docs/architecture/removed-schema.md، ويوم يفرضها
 * الحجم سيختلف شكلها عمّا كُتب اليوم على أي حال.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 80)->comment('مطبّع');
            $table->string('synonym', 80)->comment('مطبّع — مثل جوال/موبايل/تلفون');
            $table->decimal('weight', 4, 2)->default(1);
            $table->boolean('is_active')->default(true);

            $table->unique(['term', 'synonym']);
            $table->index(['term', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
    }
};
