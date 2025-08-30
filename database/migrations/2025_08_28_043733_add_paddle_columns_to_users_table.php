<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cashier Paddle uses this as the billing customer id
            if (!Schema::hasColumn('users', 'paddle_id')) {
                $table->string('paddle_id')->nullable()->index();
            }
            // Optional but useful to mirror status (active/canceled, etc.)
            if (!Schema::hasColumn('users', 'paddle_status')) {
                $table->string('paddle_status')->nullable()->index();
            }
            // Optional: if you plan to use trials
            if (!Schema::hasColumn('users', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'paddle_id')) {
                $table->dropColumn('paddle_id');
            }
            if (Schema::hasColumn('users', 'paddle_status')) {
                $table->dropColumn('paddle_status');
            }
            if (Schema::hasColumn('users', 'trial_ends_at')) {
                $table->dropColumn('trial_ends_at');
            }
        });
    }
};

