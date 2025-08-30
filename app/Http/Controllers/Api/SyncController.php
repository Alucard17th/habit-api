<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Habit;
use App\Models\HabitLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    // Accept arrays of habits/logs changed offline; upsert server-side
    public function push(Request $request) {
        $data = $request->validate([
            'habits' => 'array',
            'habits.*.id' => 'nullable|integer',
            'habits.*.name' => 'required|string|max:120',
            'habits.*.frequency' => 'required|in:daily,weekly',
            'habits.*.target_per_day' => 'nullable|integer|min:1|max:50',
            'habits.*.is_archived' => 'nullable|boolean',
            'habit_logs' => 'array',
            'habit_logs.*.habit_id' => 'required|integer',
            'habit_logs.*.log_date' => 'required|date',
            'habit_logs.*.count' => 'required|integer|min:0|max:200',
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user, $data) {
            if (!empty($data['habits'])) {
                foreach ($data['habits'] as $h) {
                    $habit = isset($h['id']) && $h['id']
                        ? Habit::where('user_id', $user->id)->where('id', $h['id'])->first()
                        : null;

                    $payload = [
                        'user_id' => $user->id,
                        'name' => $h['name'],
                        'frequency' => $h['frequency'],
                        'target_per_day' => $h['target_per_day'] ?? 1,
                        'is_archived' => $h['is_archived'] ?? false,
                    ];

                    if ($habit) $habit->update($payload);
                    else Habit::create($payload);
                }
            }

            if (!empty($data['habit_logs'])) {
                foreach ($data['habit_logs'] as $l) {
                    HabitLog::updateOrCreate(
                        ['habit_id' => $l['habit_id'], 'log_date' => $l['log_date']],
                        ['user_id' => $user->id, 'count' => $l['count']]
                    );
                }
            }
        });

        return response()->json(['message' => 'synced']);
    }

    // For client’s first load / after lastSyncAt, return user data
    public function pull(Request $request) {
        $lastSyncAt = $request->query('lastSyncAt'); // optional ISO
        $user = $request->user();

        $habits = Habit::where('user_id', $user->id)
            ->when($lastSyncAt, fn($q) => $q->where('updated_at','>=',$lastSyncAt))
            ->get();

        $logs = HabitLog::where('user_id', $user->id)
            ->when($lastSyncAt, fn($q) => $q->where('updated_at','>=',$lastSyncAt))
            ->get();

        return response()->json([
            'habits' => $habits,
            'habit_logs' => $logs,
            'serverTime' => now()->toIso8601String(),
        ]);
    }
}
