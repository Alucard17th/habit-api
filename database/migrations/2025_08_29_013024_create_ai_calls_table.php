<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ai_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('feature'); // weekly_review|atomic_habit|nl_log
            $t->json('input')->nullable();
            $t->json('output')->nullable();
            $t->unsignedInteger('ms')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('ai_calls'); }
};
