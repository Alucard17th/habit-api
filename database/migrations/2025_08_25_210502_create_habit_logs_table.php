<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('habit_logs', function (Blueprint $table) {
      $table->id();
      $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
      $table->foreignId('user_id')->constrained()->cascadeOnDelete();

      $table->date('log_date');                // yyyy-mm-dd (UTC)
      $table->unsignedSmallInteger('count')->default(0); // how many times done today

      $table->timestamps();

      $table->unique(['habit_id', 'log_date']);    // one log/day/habit
      $table->index(['user_id', 'log_date']);
    });
  }
  public function down(): void {
    Schema::dropIfExists('habit_logs');
  }
};
