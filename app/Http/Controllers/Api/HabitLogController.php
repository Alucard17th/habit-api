<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertHabitLogRequest;
use App\Models\Habit;
use App\Models\HabitLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\HabitLogEntry;

class HabitLogController extends Controller
{
    // public function index(Request $request, Habit $habit) {
    //     $this->authorizeHabit($request, $habit);

    //     $from = $request->query('from'); // Y-m-d
    //     $to   = $request->query('to');

    //     $query = $habit->logs()->where('user_id', $request->user()->id);
    //     if ($from) $query->whereDate('log_date', '>=', $from);
    //     if ($to)   $query->whereDate('log_date', '<=', $to);

    //     return response()->json($query->orderBy('log_date','desc')->get());
    // }

    public function index(Request $request, Habit $habit) {
        $this->authorizeHabit($request, $habit);

        $from = $request->query('from'); // Y-m-d
        $to   = $request->query('to');
        $include = $request->query('include'); // e.g. "entries"

        $query = $habit->logs()
            ->where('user_id', $request->user()->id)
            ->when($from, fn($q) => $q->whereDate('log_date', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('log_date', '<=', $to))
            ->orderBy('log_date','desc');

        if ($include === 'entries') {
            $query->with(['entries' => fn($e) => $e->orderBy('logged_at','asc')]);
        }

        $logs = $query->get()->map(function($log) use ($include) {
            $arr = $log->toArray();
            if ($include === 'entries') {
                // flatten to just times in ISO (or keep full objects if you prefer)
                $arr['entry_times'] = $log->entries->map(fn($e) => $e->logged_at->toIso8601String())->values();
                // optional: unset heavy entries array
                unset($arr['entries']);
            }
            return $arr;
        });

        return response()->json($logs);
    }


    // public function upsert(UpsertHabitLogRequest $request, Habit $habit) {
    //     $this->authorizeHabit($request, $habit);
    //     $data = $request->validated();

    //     $log = HabitLog::updateOrCreate(
    //         ['habit_id' => $habit->id, 'log_date' => $data['log_date']],
    //         ['user_id' => $request->user()->id, 'count' => $data['count']]
    //     );

    //     // Update streaks if "completed" (count >= target)
    //     $target = max(1, (int)$habit->target_per_day);
    //     if ($log->count >= $target) {
    //         $this->recomputeStreaks($habit);
    //     }

    //     return response()->json($log);
    // }
    
    public function upsert(UpsertHabitLogRequest $request, Habit $habit) {
        $this->authorizeHabit($request, $habit);
        $data = $request->validated();
        $newCount = max(0, (int)$data['count']); // clamp non-negative

        $log = DB::transaction(function () use ($request, $habit, $data, $newCount) {
            // find or create the day log
            $log = HabitLog::firstOrCreate(
                ['habit_id' => $habit->id, 'log_date' => $data['log_date']],
                ['user_id' => $request->user()->id, 'count' => 0]
            );

            $oldCount = (int)$log->count;

            if ($newCount > $oldCount) {
                // need to ADD entries (newCount - oldCount)
                $toAdd = $newCount - $oldCount;
                $now = now();
                // efficient bulk insert
                $rows = [];
                for ($i = 0; $i < $toAdd; $i++) {
                    $rows[] = [
                        'habit_log_id' => $log->id,
                        'logged_at'    => $now,          // or $now->copy()->addSeconds($i)
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                }
                if ($rows) {
                    HabitLogEntry::insert($rows);
                }
            } elseif ($newCount < $oldCount) {
                // need to REMOVE entries (oldCount - newCount): delete latest ones
                $toRemove = $oldCount - $newCount;
                $ids = $log->entries()
                    ->orderByDesc('logged_at')
                    ->limit($toRemove)
                    ->pluck('id')
                    ->all();
                if (!empty($ids)) {
                    HabitLogEntry::whereIn('id', $ids)->delete();
                }
            }

            // sync the aggregate
            $log->count = $newCount;
            $log->save();

            // always recompute (covers increments & decrements)
            $this->recomputeStreaks($habit);

            return $log;
        });

        // return fresh log
        return response()->json($log->fresh());
    }

    // public function toggleToday(Request $request, Habit $habit) {
    //     $this->authorizeHabit($request, $habit);

    //     $today = Carbon::now()->toDateString();
    //     $target = max(1, (int)$habit->target_per_day);

    //     $log = HabitLog::firstOrNew([
    //         'habit_id' => $habit->id,
    //         'log_date' => $today,
    //     ]);
    //     $log->user_id = $request->user()->id;
    //     $log->count   = $log->exists && $log->count >= $target ? 0 : $target; // toggle between 0 and done
    //     $log->save();

    //     $this->recomputeStreaks($habit);

    //     return response()->json($log);
    // }

    public function toggleToday(Request $request, Habit $habit) {
        $this->authorizeHabit($request, $habit);

        $today  = now()->toDateString();
        $target = max(1, (int)$habit->target_per_day);

        $log = DB::transaction(function () use ($request, $habit, $today, $target) {
            $log = HabitLog::firstOrCreate(
                ['habit_id' => $habit->id, 'log_date' => $today],
                ['user_id' => $request->user()->id, 'count' => 0]
            );

            $done = $log->count >= $target;
            if ($done) {
                // going to "not done": delete ALL entries for the day
                HabitLogEntry::where('habit_log_id', $log->id)->delete();
                $log->count = 0;
            } else {
                // going to "done": ensure we have exactly target entries
                $current = (int)$log->count;
                $toAdd   = max(0, $target - $current);
                if ($toAdd > 0) {
                    $now = now();
                    $rows = [];
                    for ($i = 0; $i < $toAdd; $i++) {
                        $rows[] = [
                            'habit_log_id' => $log->id,
                            'logged_at'    => $now,
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ];
                    }
                    HabitLogEntry::insert($rows);
                }
                $log->count = $target;
            }

            $log->save();
            $this->recomputeStreaks($habit);

            return $log;
        });

        return response()->json($log->fresh());
    }

    // protected function recomputeStreaks(Habit $habit): void {
    //     // recompute streak_current / streak_longest (daily only for MVP)
    //     // Simple: walk back day by day until a miss
    //     $today = Carbon::today();
    //     $streak = 0;

    //     for ($d = $today->copy(); $d->diffInDays($today) <= 365; $d->subDay()) {
    //         $log = $habit->logs()->whereDate('log_date', $d->toDateString())->first();
    //         if (!$log || $log->count < max(1, (int)$habit->target_per_day)) break;
    //         $streak++;
    //     }

    //     $habit->streak_current = $streak;
    //     $habit->streak_longest = max($habit->streak_longest, $streak);
    //     $habit->last_completed_date = $habit->logs()
    //         ->where('count', '>=', max(1, (int)$habit->target_per_day))
    //         ->orderByDesc('log_date')->value('log_date');
    //     $habit->save();
    // }
    protected function recomputeStreaks(Habit $habit): void
    {
        $today  = \Carbon\Carbon::today();              // MVP: server tz
        $target = max(1, (int) $habit->target_per_day);

        $streak = 0;
        $cursor = $today->copy();

        // Walk back day by day until a miss (cap lookback to 365)
        for ($i = 0; $i <= 365; $i++) {
            $done = \App\Models\HabitLog::query()
                ->where('habit_id', $habit->id)
                ->where('user_id', $habit->user_id)        // <-- important
                ->whereDate('log_date', $cursor->toDateString())
                ->where('count', '>=', $target)
                ->exists();

            if (!$done) break;

            $streak++;
            $cursor->subDay();
        }

        $habit->streak_current = $streak;
        $habit->streak_longest = max((int)$habit->streak_longest, $streak);

        $habit->last_completed_date = \App\Models\HabitLog::query()
            ->where('habit_id', $habit->id)
            ->where('user_id', $habit->user_id)            // <-- important
            ->where('count', '>=', $target)
            ->orderByDesc('log_date')
            ->value('log_date');

        $habit->save();
}


    protected function authorizeHabit(Request $request, Habit $habit): void {
        abort_if($habit->user_id !== $request->user()->id, 403, 'Forbidden');
    }
}
