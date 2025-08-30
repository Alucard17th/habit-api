<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('habits', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained()->cascadeOnDelete();

      $table->string('name');
      // daily/weekly support; extend later with cron-like custom rules if needed
      $table->enum('frequency', ['daily', 'weekly'])->default('daily')->index();
      // e.g., drink water 3x/day
      $table->unsignedSmallInteger('target_per_day')->default(1);

      // optional UX niceties
      $table->time('reminder_time')->nullable();

      // streak tracking (server-side convenience to avoid heavy queries)
      $table->unsignedInteger('streak_current')->default(0);
      $table->unsignedInteger('streak_longest')->default(0);
      $table->date('last_completed_date')->nullable();

      $table->boolean('is_archived')->default(false)->index();
      $table->softDeletes();
      $table->timestamps();

      $table->index(['user_id', 'is_archived']);
    });
  }
  public function down(): void {
    Schema::dropIfExists('habits');
  }
};
