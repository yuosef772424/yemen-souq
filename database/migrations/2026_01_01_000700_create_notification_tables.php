<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 80)->comment('outbid, listing_approved, new_message ...');
            $table->string('title', 180);
            $table->string('body', 500)->nullable();
            $table->json('data')->nullable();
            $table->enum('channel', ['in_app', 'push', 'sms', 'email'])->default('in_app');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('notification_type', 80);
            $table->boolean('in_app')->default(true);
            $table->boolean('push')->default(true);
            $table->boolean('sms')->default(false);
            $table->boolean('email')->default(false);

            $table->primary(['user_id', 'notification_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('app_notifications');
    }
};
