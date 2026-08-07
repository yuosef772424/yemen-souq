<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وحدة البحث لا تملك أي بيانات أصلية.
 * كل صف هنا مشتق من listings ويُعاد بناؤه عند حدث ListingSaved
 * أو عند تغيّر سعر الصرف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_index', function (Blueprint $table) {
            $table->foreignId('listing_id')->primary()->constrained('listings')->cascadeOnDelete();
            $table->string('title_normalized', 180);
            $table->text('search_blob')->comment('عنوان + وصف + تصنيف + موقع، مطبّع');
            $table->unsignedBigInteger('category_id');
            $table->string('category_path', 255);
            $table->unsignedBigInteger('location_id');
            $table->string('location_path', 255);
            $table->decimal('price_in_base', 18, 2)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->enum('sale_type', ['direct', 'auction']);
            $table->enum('condition_type', ['new', 'like_new', 'used', 'for_parts'])->nullable();
            $table->boolean('has_image')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->decimal('quality_score', 6, 3)->default(0)->comment('اكتمال الإعلان: صور، وصف، صفات');
            $table->decimal('popularity_score', 6, 3)->default(0)->comment('يُحدَّث من وحدة التحليلات');
            $table->timestamp('published_at')->nullable();
            $table->dateTime('indexed_at');

            $table->index(['category_path', 'price_in_base'], 'idx_si_category');
            $table->index(['location_path', 'published_at'], 'idx_si_location');
            $table->index('price_in_base', 'idx_si_price');
            $table->index(['is_featured', 'popularity_score', 'published_at'], 'idx_si_ranking');
            $table->fullText('search_blob', 'ft_si_blob');
        });

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
        Schema::dropIfExists('search_index');
    }
};
