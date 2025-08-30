<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('coach_suggestions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('habit_id')->nullable()->constrained('habits')->nullOnDelete();
            $t->string('type');        // encourage | adjust | congratulate
            $t->string('code');        // a stable code like missed_3_days, streak_7, morning_shift
            $t->string('title');       // short headline
            $t->text('message');       // full copy for UI
            $t->json('payload')->nullable(); // suggested changes e.g. {"suggest_target": 3, "suggest_time":"morning"}
            $t->enum('status', ['pending','accepted','dismissed'])->default('pending');
            $t->timestamp('valid_until')->nullable(); // optional expiry
            $t->timestamps();

            $t->unique(['user_id','habit_id','code','status']); // avoid dup pending for same rule
        });
    }
    public function down(): void { Schema::dropIfExists('coach_suggestions'); }
};
