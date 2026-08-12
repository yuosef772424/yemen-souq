<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary()->comment('ISO 4217: YER, SAR, USD');
            $table->string('name_ar', 60);
            $table->string('name_en', 60);
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_base')->default(false)->comment('عملة الأساس — واحدة فقط');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency_code', 3);
            $table->decimal('rate_to_base', 18, 6)
                ->comment('كم وحدة من عملة الأساس تساوي وحدة من هذه العملة');
            $table->date('effective_date');
            $table->string('source', 60)->default('manual');
            $table->string('note', 255)->nullable()->comment('مثلاً: سعر صنعاء أو عدن');
            $table->timestamp('created_at')->nullable();

            $table->unique(['currency_code', 'effective_date']);
            $table->index(['currency_code', 'effective_date']);
            $table->foreign('currency_code')->references('code')->on('currencies')->cascadeOnDelete();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->cascadeOnDelete();
            $table->string('name_ar', 120);
            $table->string('name_normalized', 120);
            $table->string('slug', 140)->unique();
            $table->enum('level', ['country', 'governorate', 'district', 'area']);
            $table->string('path', 255)->comment('مثل 1/7/42 — للبحث في كل الفروع');
            $table->unsignedTinyInteger('depth')->default(0);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'is_active', 'sort_order']);
            $table->index('path');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->string('name_ar', 120);
            $table->string('name_normalized', 120);
            $table->string('slug', 140)->unique();
            $table->string('icon', 60)->nullable();
            $table->string('path', 255)->comment('مثل 1/14/57');
            $table->unsignedTinyInteger('depth')->default(0);
            // لا `allows_auction`: مفتاحٌ يفتح ميزةً محذوفة — انظر
            // docs/architecture/removed-schema.md
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['parent_id', 'is_active', 'sort_order']);
            $table->index('path');
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('attr_key', 60)->unique()->comment('مثل mileage, rooms, year');
            $table->string('name_ar', 120);
            $table->enum('data_type', ['string', 'number', 'boolean', 'option', 'multi_option', 'date']);
            $table->string('unit', 20)->nullable()->comment('كم، م٢، سنة');
            $table->decimal('min_value', 18, 4)->nullable();
            $table->decimal('max_value', 18, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('attribute_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('value_ar', 120);
            $table->string('value_normalized', 120);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->index(['attribute_id', 'is_active', 'sort_order']);
        });

        Schema::create('category_attributes', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(true);
            $table->smallInteger('sort_order')->default(0);

            $table->primary(['category_id', 'attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('attribute_options');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
