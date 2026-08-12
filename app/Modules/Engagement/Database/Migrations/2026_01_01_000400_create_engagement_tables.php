<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حُذفت من هذه الهجرة: `auctions` و`bids` و`saved_searches` و`reviews`.
 *
 * أربعتها أُنشئت ولم يكتب فيها سطر واحد. وتعريفها كاملاً محفوظ في
 * docs/architecture/removed-schema.md لتُعاد يوم تُبنى ميزتها.
 *
 * والحذف في موضعين لا واحد: هنا لتخرج قاعدةٌ جديدة نظيفة، وفي هجرة
 * `2026_01_01_000402_drop_unwired_engagement_tables` لتلحق القواعد
 * المهاجَرة أصلاً. الاكتفاء بالأولى يترك الجداول قائمةً عند من شغّل
 * المشروع قبل اليوم، والاكتفاء بالثانية يُنشئ أربعة جداول ثم يُسقطها
 * في كل تثبيت جديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->dateTime('created_at');

            // المفتاح المركّب يمنع التكرار في القاعدة نفسها لا في كود التطبيق
            $table->primary(['user_id', 'listing_id']);
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
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('listing_reports');
        Schema::dropIfExists('favorites');
    }
};
