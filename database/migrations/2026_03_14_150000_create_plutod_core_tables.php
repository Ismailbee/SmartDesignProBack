<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_verifications', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('email')->index();
            $table->string('otp_hash');
            $table->timestamp('expires_at')->index();
            $table->boolean('verified')->default(false);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('email')->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('status')->index();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('reference')->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable()->index();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('currency')->default('NGN');
            $table->string('type')->default('purchase')->index();
            $table->string('plan')->nullable()->index();
            $table->unsignedInteger('tokens')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('channel')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('source')->nullable();
            $table->string('credited_by')->nullable();
            $table->string('report_id')->nullable()->index();
            $table->text('reason')->nullable();
            $table->text('refund_reason')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('info')->index();
            $table->string('title');
            $table->text('message');
            $table->boolean('read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->string('sent_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('timestamp')->index();
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('referred_user_email')->index();
            $table->string('referred_user_name')->nullable();
            $table->string('referral_code')->index();
            $table->json('tokens_awarded');
            $table->string('status')->default('completed');
            $table->timestamps();
            $table->unique(['referrer_id', 'referred_user_id']);
        });

        Schema::create('payment_reports', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->index();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedInteger('tokens')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('PlutoD');
            $table->string('site_url')->default('https://plutod.com');
            $table->string('support_email')->default('support@plutod.com');
            $table->boolean('maintenance_mode')->default(false);
            $table->boolean('allow_registration')->default(true);
            $table->boolean('require_email_verification')->default(false);
            $table->unsignedInteger('max_upload_size')->default(50);
            $table->boolean('enable_ai')->default(true);
            $table->string('default_user_plan')->default('Basic');
            $table->unsignedInteger('session_timeout')->default(120);
            $table->unsignedInteger('max_free_tokens')->default(16);
            $table->json('pricing')->nullable();
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->index();
            $table->json('tags')->nullable();
            $table->string('status')->default('draft')->index();
            $table->string('access_level')->default('free')->index();
            $table->unsignedBigInteger('price')->default(0);
            $table->string('thumbnail_url')->nullable();
            $table->string('file_url')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('payment_reports');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('password_reset_requests');
        Schema::dropIfExists('otp_verifications');
    }
};