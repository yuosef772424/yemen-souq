<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // جدول users أنشأته هجرة لارافل الافتراضية — نوسّعه بدل إعادة إنشائه
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('name')
                ->comment('بصيغة E.164 مثل +9677xxxxxxx');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->enum('status', ['active', 'suspended', 'banned'])->default('active')->after('password');
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->string('display_name', 120);
            $table->string('name_normalized', 120);
            $table->string('avatar_path', 255)->nullable();
            $table->string('bio', 500)->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->char('preferred_currency', 3)->nullable();
            $table->string('preferred_locale', 10)->default('ar');
            $table->boolean('is_business')->default(false);
            $table->string('business_name', 160)->nullable();
            $table->decimal('trust_score', 5, 2)->default(0)->comment('محسوب — لا يُحرَّر يدوياً');
            $table->unsignedInteger('listings_count')->default(0);
            $table->timestamps();

            $table->foreign('preferred_currency')->references('code')->on('currencies')->nullOnDelete();
        });

        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('code_hash', 255)->comment('لا يُخزَّن الرمز نصاً صريحاً');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_prefix', 45)->nullable()->comment('مقطوع — لمنع الإساءة فقط');
            $table->timestamp('created_at')->nullable();

            $table->index(['phone', 'expires_at']);
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('device_uuid');
            $table->enum('platform', ['web', 'android', 'ios']);
            $table->string('push_token', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'device_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('phone_verifications');
        Schema::dropIfExists('user_profiles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['phone', 'phone_verified_at', 'status', 'last_login_at', 'deleted_at']);
        });
    }
};
