<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HabitController;
use App\Http\Controllers\Api\HabitLogController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PaddleController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\CoachAiController;


Route::post('/auth/register', function (\Illuminate\Http\Request $r) {
    $data = $r->validate([
        'name' => 'required|string|max:120',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|string|min:8'
    ]);
    $user = \App\Models\User::create([
        'name' => $data['name'],
        'email' => $data['email'],
        'password' => bcrypt($data['password']),
    ]);
    $token = $user->createToken('api')->plainTextToken;
    return response()->json(['token' => $token, 'user' => $user], 201);
});

Route::post('/auth/login', function (\Illuminate\Http\Request $r) {
    $data = $r->validate([
        'email' => 'required|email',
        'password' => 'required|string'
    ]);
    if (!auth()->attempt($data)) {
        return response()->json(['message' => 'Invalid credentials'], 422);
    }
    $user  = \App\Models\User::where('email', $data['email'])->first();
    $token = $user->createToken('api')->plainTextToken;
    return response()->json(['token' => $token, 'user' => $user]);
});

Route::middleware('auth:sanctum')->group(function () {
    // Habits
    Route::get('/habits', [HabitController::class, 'index']);
    Route::post('/habits', [HabitController::class, 'store']);
    Route::get('/habits/{habit}', [HabitController::class, 'show']);
    Route::put('/habits/{habit}', [HabitController::class, 'update']);
    Route::delete('/habits/{habit}', [HabitController::class, 'destroy']);
    Route::patch('/habits/{habit}/archive', [HabitController::class, 'archive']);     // body: { archived: true|false }

    // Logs
    Route::get('/habits/{habit}/logs', [HabitLogController::class, 'index']);
    Route::post('/habits/{habit}/logs/upsert', [HabitLogController::class, 'upsert']);
    Route::post('/habits/{habit}/logs/toggle-today', [HabitLogController::class, 'toggleToday']);

    // Analytics
    Route::get('/analytics/summary', [AnalyticsController::class, 'summary']);

    // Purchases (MVP confirm)
    Route::post('/purchases/confirm', [PurchaseController::class, 'confirm']);

    // Sync
    Route::post('/sync/push', [SyncController::class, 'push']);
    Route::get('/sync/pull', [SyncController::class, 'pull']);

    // Me
    Route::get('/me', [ProfileController::class, 'show']);                 // you already have this; keep for completeness
    Route::put('/me', [ProfileController::class, 'update']);               // update name/email
    Route::put('/me/password', [ProfileController::class, 'updatePassword']); // change password

    // Coach
    Route::get('/coach/suggestions', [CoachController::class, 'index']);
    Route::post('/coach/suggestions/{id}/accept', [CoachController::class, 'accept']);
    Route::post('/coach/suggestions/{id}/dismiss', [CoachController::class, 'dismiss']);

    // AI
    Route::get('/coach/ai/weekly', [CoachAiController::class, 'weekly']);         // GET cached or generate
    Route::post('/coach/ai/atomic', [CoachAiController::class, 'atomic']);        // POST { text }
    Route::post('/coach/ai/parse-log', [CoachAiController::class, 'parseLog']);   // POST { message }

    // Paddle
    Route::post('/billing/paddle/checkout/product', [PaddleController::class, 'checkout']);
});
