<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('weekly_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('week_start'); // Monday (or user TZ start)
            $t->json('payload');    // cached AI JSON
            $t->timestamps();
            $t->unique(['user_id','week_start']);
        });
    }
    public function down(): void { Schema::dropIfExists('weekly_reviews'); }
};
