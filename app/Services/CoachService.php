<?php

namespace App\Services;

use App\Models\CoachSuggestion;
use App\Models\Habit;
use App\Models\HabitLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CoachService
{
    /**
     * Generate pending suggestions for a user (idempotent per day).
     * - Inserts new 'pending' suggestions if not already present.
     * - Returns current pending suggestions.
     */
    public function generateForUser(int $userId): array
    {
        // load recent logs (last 30 days) for all habits
        $since = Carbon::today()->subDays(30)->toDateString();

        // pull habits to consider
        $habits = Habit::where('user_id', $userId)
            ->where('is_archived', false)->get(['id','name','target_per_day']);

        if ($habits->isEmpty()) return [];

        // map: habit_id => logs (ordered by date asc)
        $logsByHabit = HabitLog::where('user_id', $userId)
            ->whereIn('habit_id', $habits->pluck('id'))
            ->where('log_date', '>=', $since)
            ->orderBy('log_date')
            ->get(['habit_id','log_date','count'])
            ->groupBy('habit_id');

        $pending = [];

        foreach ($habits as $h) {
            $logs = ($logsByHabit[$h->id] ?? collect())->values();
            $target = max(1, (int)($h->target_per_day ?? 1));

            // ---------- Rule 1: Missed 3 days in a row ----------
            $missed3 = $this->missedDaysInARow($logs, 3);
            if ($missed3) {
                $this->upsertPending($userId, $h->id,
                    type: 'adjust',
                    code: 'missed_3_days',
                    title: "Let’s make “{$h->name}” easier",
                    message: "You’ve missed it 3 days in a row. Want to reduce the daily target or move it earlier?",
                    payload: [
                        'suggest_target' => max(1, (int) floor(($h->target_per_day ?? 1) * 0.8)),
                        'suggest_time'   => 'morning'
                    ]
                );
            }

            // ---------- Rule 2: Streak >= 7 ----------
            $streak = $this->currentStreak($logs);
            if ($streak >= 7) {
                $this->upsertPending($userId, $h->id,
                    type: 'congratulate',
                    code: 'streak_7',
                    title: "🔥 {$streak}-day streak on “{$h->name}”! ",
                    message: "Amazing consistency! Want to increase the target slightly or keep as is?",
                    payload: [
                        'suggest_target' => (int) ceil(($h->target_per_day ?? 1) * 1.2),
                    ]
                );
            }

            // ---------- Rule 3: Morning trend (avg hour < 10) ----------
            // If your logs only have a DATE (no time), skip this section or derive from client metadata.
            $avgHour = $this->avgCompletionHour($logs);
            if ($avgHour !== null && $avgHour < 10) {
                $this->upsertPending($userId, $h->id,
                    type: 'adjust',
                    code: 'morning_shift',
                    title: "“{$h->name}” works best in the morning",
                    message: "We noticed you usually complete it before 10 AM. Set it as a morning habit?",
                    payload: ['suggest_time' => 'morning']
                );
            }

            // ---------- Rule 4: Recovery nudge after drop ----------
            if ($streak === 0 && $this->hadLongerStreak($logs, 5)) {
                $this->upsertPending($userId, $h->id,
                    type: 'encourage',
                    code: 'streak_recovery',
                    title: "Let’s bounce back on “{$h->name}”",
                    message: "You had a great streak recently. Try a 3-day mini-streak to get momentum again?",
                    payload: ['mini_streak_days' => 3]
                );
            }

            // ---------- Rule A: undershoot 2 of last 3 days ----------
            if ($this->undershot2of3($logs, $target)) {
                $this->upsertPending(
                    $userId, $h->id,
                    type: 'adjust',
                    code: 'undershoot_2of3',
                    title: "Make “{$h->name}” more achievable",
                    message: "You were below target on 2 of the last 3 days. Want to lower the daily target or add a reminder?",
                    payload: ['suggest_target' => max(1, (int) floor($target * 0.8))]
                );
            }

            // ---------- Rule B: overshoot today by >=20% ----------
            if ($this->overshotToday($logs, $target, 0.2)) {
                $this->upsertPending(
                    $userId, $h->id,
                    type: 'congratulate',
                    code: 'overshoot_today_20',
                    title: "Crushing “{$h->name}” today!",
                    message: "You exceeded today’s target by over 20%. Want to nudge the target up a bit?",
                    payload: ['suggest_target' => (int) ceil($target * 1.2)]
                );
            }

            // ---------- Rule C: missed 3 calendar days in a row (gap-based) ----------
            if ($this->missedDaysInARowByCalendar($logs, 3)) {
                $this->upsertPending(
                    $userId, $h->id,
                    type: 'adjust',
                    code: 'missed_3_days',
                    title: "Let’s make “{$h->name}” easier",
                    message: "You’ve missed it 3 days in a row. Try reducing the daily target or doing it earlier.",
                    payload: ['suggest_target' => max(1, (int) floor($target * 0.8))]
                );
            }

            // ---------- Rule D: no reminder + at least one miss in last 3 days ----------
            if (empty($h->reminder_time) && $this->missedAtLeastOnceInLast3($logs)) {
                $this->upsertPending(
                    $userId, $h->id,
                    type: 'encourage',
                    code: 'add_reminder_last3',
                    title: "Add a reminder for “{$h->name}”",
                    message: "A quick reminder can keep you on track. Set a time to get a gentle nudge each day?",
                    payload: ['suggest_time' => 'morning'] // or leave null and let UI choose
                );
            }
        }

        // return all current pending suggestions
        return CoachSuggestion::where('user_id', $userId)
            ->where('status', 'pending')
            ->latest()->get()->toArray();
    }

    // --- helpers ---

    private function upsertPending(
        int $userId, int $habitId,
        string $type, string $code, string $title, string $message, array $payload = []
    ): void {
        CoachSuggestion::firstOrCreate(
            ['user_id' => $userId, 'habit_id' => $habitId, 'code' => $code, 'status' => 'pending'],
            compact('type','title','message') + ['payload' => $payload]
        );
    }

    private function currentStreak($logs): int {
        $streak = 0;
        foreach ($logs->reverse() as $l) {
            if (($l->count ?? 0) > 0) $streak++;
            else break;
        }
        return $streak;
    }

    private function missedDaysInARow($logs, int $n): bool {
        if ($logs->count() < $n) return false;
        $recent = $logs->take(-$n); // last n
        return $recent->every(fn($l) => (int)($l->count ?? 0) === 0);
    }

    private function hadLongerStreak($logs, int $threshold): bool {
        $best = 0; $cur = 0;
        foreach ($logs as $l) {
            if (($l->count ?? 0) > 0) { $cur++; $best = max($best, $cur); }
            else $cur = 0;
        }
        return $best >= $threshold;
    }

    /**
     * If you store timestamps, compute average completion hour (0-23); otherwise return null.
     * Replace with your own logic if you only have dates.
     */
    private function avgCompletionHour($logs): ?int {
        // Example: if you store a separate completed_at; otherwise remove rule 3.
        if (!$logs->first() || !isset($logs->first()->completed_at)) return null;
        $hours = $logs->pluck('completed_at')->filter()
            ->map(fn($ts) => Carbon::parse($ts)->hour);
        if ($hours->count() < 5) return null;
        return (int) floor($hours->avg());
    }

    private function undershot2of3(\Illuminate\Support\Collection $logs, int $target): bool {
        if ($logs->isEmpty()) return false;
        $last3 = $logs->take(-3); // last three rows
        if ($last3->count() < 3) return false;
        $unders = $last3->filter(fn($l) => (int)($l->count ?? 0) < $target)->count();
        return $unders >= 2;
    }

    private function overshotToday(\Illuminate\Support\Collection $logs, int $target, float $threshold = 0.2): bool {
        if ($logs->isEmpty()) return false;
        $today = \Carbon\Carbon::today()->toDateString();

        $row = $logs->filter(fn($l) => $l->log_date === $today)->last();
        if (!$row) return false;

        $count = (int)($row->count ?? 0);
        return $count >= (int) ceil($target * (1 + $threshold));
    }


    private function missedAtLeastOnceInLast3(\Illuminate\Support\Collection $logs): bool {
        if ($logs->isEmpty()) return false;
        $last3 = $logs->take(-3);
        if ($last3->count() < 3) return false;
        return $last3->filter(fn($l) => (int)($l->count ?? 0) === 0)->isNotEmpty();
    }

    // Gap-based “missed n days in a row” even if you don’t store count=0 rows
    private function missedDaysInARowByCalendar(\Illuminate\Support\Collection $logs, int $n): bool {
        $doneSet = $logs->filter(fn($l) => (int)($l->count ?? 0) > 0)
                        ->map(fn($l) => \Carbon\Carbon::parse($l->log_date)->toDateString())
                        ->flip(); // keys for O(1) lookup
        $today = \Carbon\Carbon::today();
        $misses = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = $today->copy()->subDays($i)->toDateString();
            if (!isset($doneSet[$d])) $misses++;
            else break;
        }
        return $misses >= $n;
    }
}
