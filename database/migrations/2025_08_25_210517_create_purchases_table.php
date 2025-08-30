<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('purchases', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained()->cascadeOnDelete();

      $table->enum('provider', ['stripe','gumroad','paddle','manual'])->index();
      $table->string('product_code')->index();          // e.g. HABIT_PRO
      $table->string('provider_txn_id')->nullable()->index();
      $table->integer('amount_cents')->default(0);
      $table->string('currency', 10)->default('USD');
      $table->enum('status', ['pending','paid','failed','refunded'])->default('pending')->index();
      $table->timestamp('purchased_at')->nullable();
      $table->json('payload')->nullable();

      $table->timestamps();
    });
  }
  public function down(): void {
    Schema::dropIfExists('purchases');
  }
};
