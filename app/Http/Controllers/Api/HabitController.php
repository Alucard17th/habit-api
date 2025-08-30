<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHabitRequest;
use App\Http\Requests\UpdateHabitRequest;
use App\Models\Habit;
use Illuminate\Http\Request;

class HabitController extends Controller
{
    public function index(Request $request) {
        $habits = Habit::where('user_id', $request->user()->id)
            ->where('is_archived', false)
            ->orderBy('created_at','desc')
            ->get();

        return response()->json($habits);
    }

    public function store(StoreHabitRequest $request) {
        $data = $request->validated();
        $habit = Habit::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'target_per_day' => $data['target_per_day'] ?? 1,
            'reminder_time' => $data['reminder_time'] ?? null,
        ]);
        return response()->json($habit, 201);
    }

    public function show(Request $request, Habit $habit) {
        $this->authorizeHabit($request, $habit);
        return response()->json($habit->load('logs'));
    }

    public function update(UpdateHabitRequest $request, Habit $habit) {
        $this->authorizeHabit($request, $habit);
        $habit->update($request->validated());
        return response()->json($habit);
    }

    public function destroy(Request $request, Habit $habit) {
        $this->authorizeHabit($request, $habit);
        $habit->delete();
        return response()->json(['message' => 'deleted']);
    }

    public function archive(Request $request, Habit $habit) {
        $this->authorizeHabit($request, $habit);

        $validated = $request->validate([
            'archived' => 'required|boolean',
        ]);

        $habit->is_archived = $validated['archived'];
        $habit->save();

        return response()->json($habit);
    }

    protected function authorizeHabit(Request $request, Habit $habit): void {
        abort_if($habit->user_id !== $request->user()->id, 403, 'Forbidden');
    }
}
