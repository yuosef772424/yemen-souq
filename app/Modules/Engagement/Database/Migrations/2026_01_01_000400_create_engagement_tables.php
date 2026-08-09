<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->char('currency_code', 3)->comment('ثابتة طوال المزاد — لا تتغير بعد أول مزايدة');
            $table->decimal('start_price', 15, 2);
            $table->decimal('reserve_price', 15, 2)->nullable()->comment('مخفي عن المزايدين');
            $table->decimal('bid_increment', 15, 2)->default(1);
            $table->decimal('current_price', 15, 2);
            $table->decimal('current_in_base', 18, 2);
            $table->unsignedInteger('bids_count')->default(0);
            $table->unsignedBigInteger('winning_bid_id')->nullable()
                ->comment('بلا مفتاح أجنبي — تجنباً لاعتماد دائري مع bids');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('extends_on_late_bid')->default(true)->comment('تمديد تلقائي لمنع القنص');
            $table->enum('status', ['scheduled', 'running', 'ended', 'cancelled'])->default('scheduled');
            $table->timestamps();

            $table->index(['status', 'ends_at']);
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });

        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->decimal('amount_in_base', 18, 2);
            $table->boolean('is_auto')->default(false)->comment('مزايدة تلقائية بحد أقصى');
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->enum('status', ['active', 'outbid', 'won', 'retracted'])->default('active');
            $table->dateTime('created_at');

            $table->index(['auction_id', 'amount']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->dateTime('created_at');

            // المفتاح المركّب يمنع التكرار في القاعدة نفسها لا في كود التطبيق
            $table->primary(['user_id', 'listing_id']);
        });

        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->json('filters')->comment('التصنيف، الموقع، نطاق السعر، العملة');
            $table->boolean('notify')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('listing_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('reason', ['spam', 'fraud', 'wrong_category', 'prohibited', 'duplicate', 'other']);
            $table->string('details', 500)->nullable();
            $table->enum('status', ['open', 'reviewing', 'actioned', 'dismissed'])->default('open');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['status', 'created_at']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->enum('status', ['open', 'archived', 'blocked'])->default('open');
            $table->timestamp('created_at')->nullable();

            $table->unique(['listing_id', 'buyer_id']);
            $table->index(['buyer_id', 'last_message_at']);
            $table->index(['seller_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->comment('1..5');
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->unique(['listing_id', 'reviewer_id']);
            $table->index(['reviewed_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('listing_reports');
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('bids');
        Schema::dropIfExists('auctions');
    }
};
