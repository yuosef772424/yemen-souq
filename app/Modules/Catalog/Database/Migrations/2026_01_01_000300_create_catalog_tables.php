<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            $table->string('title', 180);
            $table->string('title_normalized', 180)
                ->comment('بلا تشكيل، أ/إ/آ->ا، ة->ه، ى->ي');
            $table->string('slug', 220)->unique();
            $table->text('description')->nullable();
            $table->text('description_normalized')->nullable();

            // المال مخزّن مرتين عمداً: الأصل للعرض، والمحوّل للفلترة
            $table->decimal('price_amount', 15, 2)->nullable()->comment('كما أدخله المعلن');
            $table->char('currency_code', 3)->nullable();
            $table->decimal('price_in_base', 18, 2)->nullable()->comment('محسوب — للفلترة والترتيب فقط');
            $table->decimal('rate_used', 18, 6)->nullable()->comment('سعر الصرف وقت الحساب — للتدقيق');
            $table->date('rate_date')->nullable();
            $table->boolean('is_negotiable')->default(false);
            $table->enum('price_type', ['fixed', 'negotiable', 'on_request', 'free'])->default('fixed');

            // لا `sale_type`: قيمتاه direct/auction، والمزاد محذوف —
            // فالعمود سؤالٌ بجواب واحد. انظر removed-schema.md
            $table->enum('condition_type', ['new', 'like_new', 'used', 'for_parts'])->nullable();
            $table->enum('status', ['draft', 'pending', 'active', 'paused', 'sold', 'expired', 'rejected'])
                ->default('draft');
            $table->string('reject_reason', 255)->nullable();

            $table->string('contact_phone', 20)->nullable();
            $table->boolean('hide_phone')->default(false);

            $table->unsignedInteger('views_count')->default(0)->comment('عدّاد سريع — التفصيل في وحدة التحليلات');
            $table->unsignedInteger('favorites_count')->default(0);
            $table->unsignedInteger('contacts_count')->default(0);

            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // status أولاً لأنه يقصي أكبر عدد من الصفوف
            $table->index(['status', 'category_id', 'published_at'], 'idx_browse_category');
            $table->index(['status', 'location_id', 'published_at'], 'idx_browse_location');
            $table->index(['status', 'price_in_base'], 'idx_price');
            $table->index(['user_id', 'status', 'created_at'], 'idx_user_listings');
            $table->index(['status', 'is_featured', 'featured_until'], 'idx_featured');
            $table->index(['status', 'expires_at'], 'idx_expiry');

            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });

        Schema::create('listing_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('thumb_path', 255)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['listing_id', 'sort_order']);
        });

        Schema::create('listing_attribute_values', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('value_string', 255)->nullable();
            $table->decimal('value_number', 18, 4)->nullable()->comment('منفصل لتمكين الفلترة الرقمية');
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->foreignId('option_id')->nullable()->constrained('attribute_options')->nullOnDelete();

            $table->primary(['listing_id', 'attribute_id']);
            $table->index(['attribute_id', 'value_number']);
            $table->index(['attribute_id', 'option_id']);
        });

        Schema::create('listing_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->decimal('old_amount', 15, 2)->nullable();
            $table->char('old_currency', 3)->nullable();
            $table->decimal('new_amount', 15, 2)->nullable();
            $table->char('new_currency', 3)->nullable();
            $table->decimal('new_in_base', 18, 2)->nullable();
            $table->dateTime('changed_at');

            $table->index(['listing_id', 'changed_at']);
        });
        Schema::create('listing_multi_options', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('attribute_options')->cascadeOnDelete();

            $table->primary(['listing_id', 'attribute_id', 'option_id']);
            $table->index(['attribute_id', 'option_id'], 'idx_lmo_filter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_price_history');
        Schema::dropIfExists('listing_multi_options');
        Schema::dropIfExists('listing_attribute_values');
        Schema::dropIfExists('listing_images');
        Schema::dropIfExists('listings');
    }
};
