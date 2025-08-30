<?php

namespace App\Http\Controllers;

use App\Models\AiCall;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\WeeklyReview;
use App\Services\AiClient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CoachAiController extends Controller
{
    public function weekly(Request $r, AiClient $ai)
    {
        $u  = $r->user();
        $tz = $u->timezone ?? 'UTC';

        // Week start (Monday) in user's TZ
        $today     = \Carbon\Carbon::today($tz);
        $weekStart = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();

        // Allow refresh to bypass cache (?refresh=1)
        $refresh = filter_var($r->query('refresh', '0'), FILTER_VALIDATE_BOOLEAN);
        if (!$refresh) {
            $cached = \App\Models\WeeklyReview::where('user_id', $u->id)
                ->where('week_start', $weekStart)
                ->first();
            if ($cached) {
                return response()->json(['data' => $cached->payload, 'cached' => true]);
            }
        }

        // ------- Load data (habits + logs for this week) -------
        $habits = \App\Models\Habit::where('user_id', $u->id)
            ->where('is_archived', false)
            ->get(['id', 'name', 'target_per_day']);

        if ($habits->isEmpty()) {
            $empty = ['wins'=>[], 'stumbles'=>[], 'patterns'=>[], 'next_actions'=>[]];
            \App\Models\WeeklyReview::updateOrCreate(
                ['user_id' => $u->id, 'week_start' => $weekStart],
                ['payload' => $empty]
            );
            return response()->json(['data' => $empty, 'cached' => false]);
        }

        $since = \Carbon\Carbon::parse($weekStart, $tz)->toDateString();

        $logs  = \App\Models\HabitLog::where('user_id', $u->id)
            ->whereIn('habit_id', $habits->pluck('id'))
            ->where('log_date', '>=', $since)
            ->orderBy('log_date')
            ->get(['habit_id', 'log_date', 'count']);

        // Collect unique dates and label them "Thu (Aug 28)" in user's TZ
        $dates = $logs->pluck('log_date')->map(function($d) use ($tz) {
            $date = $d instanceof \DateTimeInterface ? $d->toDateString() : (string)$d;
            return $date;
        })->unique()->sort()->values()->all();

        $dateLabels = [];
        foreach ($dates as $d) {
            $dateLabels[$d] = \Carbon\Carbon::parse($d, $tz)->isoFormat('ddd (MMM D)');
        }

        // Map per habit: last3 series, bullets, suggestions
        $perHabit = [];
        foreach ($habits as $h) {
            $target = max(1, (int)($h->target_per_day ?? 1));

            $series = $logs->where('habit_id', $h->id)
                ->mapWithKeys(function ($r) {
                    $date = $r->log_date instanceof \DateTimeInterface
                        ? $r->log_date->toDateString()
                        : (string)$r->log_date;
                    return [$date => (int)$r->count];
                })->all();

            // Ensure ascending by date
            ksort($series);

            // Last 3 entries (by calendar presence)
            $last3 = array_slice($series, -3, 3, true); // ["YYYY-MM-DD" => count]

            // Build exact numeric bullets (NO interpretation)
            $bullets = [];
            foreach ($last3 as $d => $cnt) {
                $label = $dateLabels[$d] ?? $d;
                if ($cnt >= $target) {
                    $bullets[] = "{$h->name}: {$cnt}/{$target} on {$label} (met or above)";
                } else {
                    $diff = $target - $cnt;
                    $bullets[] = "{$h->name}: {$cnt}/{$target} on {$label} (under by {$diff})";
                }
            }

            // Lightweight, safe suggestions (we trust these)
            $suggestions = [];
            // Rule: under on ≥ 2 of last 3 -> lower target ~20%
            $unders = 0;
            foreach ($last3 as $cnt) if ((int)$cnt < $target) $unders++;
            if ($unders >= 2) {
                $suggestions[] = [
                    'title'  => "Lower {$h->name} target from {$target} → ".max(1, (int)floor($target * 0.8)),
                    'why'    => "{$h->name} was under target on {$unders} of the last 3 days.",
                    'steps'  => ["Open {$h->name} in the app", "Set the daily target lower", "Keep for 7 days, then reassess"],
                    'effort' => 'low',
                ];
            }

            // Rule: last day within 1 below target -> add reminder
            $lastDate = array_key_last($last3);
            if ($lastDate !== null) {
                $lastCnt = (int)$last3[$lastDate];
                if ($lastCnt >= $target - 1 && $lastCnt < $target) {
                    $suggestions[] = [
                        'title'  => "Add a reminder for {$h->name}",
                        'why'    => "{$h->name} was just below target recently.",
                        'steps'  => ["Pick a time that fits your routine", "Enable a daily reminder", "Review after 7 days"],
                        'effort' => 'low',
                    ];
                }
            }

            // Rule: missed then met → mini-streak nudge
            if (count($last3) >= 2) {
                $last3Vals = array_values($last3);
                $prev = $last3Vals[count($last3Vals)-2] ?? null;
                $last = $last3Vals[count($last3Vals)-1] ?? null;
                if ($prev !== null && $last !== null && $prev < $target && $last >= $target) {
                    $suggestions[] = [
                        'title'  => "Start a 3-day mini-streak for {$h->name}",
                        'why'    => "You bounced back after a miss—ride the momentum.",
                        'steps'  => ["Mark the next 3 days", "Complete the habit daily", "Celebrate and reassess"],
                        'effort' => 'low',
                    ];
                }
            }

            $perHabit[] = [
                'name'        => (string)$h->name,
                'target'      => $target,
                'last3'       => $last3,   // ISO dates -> counts
                'bullets'     => $bullets, // exact numeric statements
                'suggestions' => $suggestions,
            ];
        }

        // Best/worst day by total count across habits (ties: earliest wins)
        $totalsByDay = [];
        foreach ($perHabit as $h) {
            foreach ($h['last3'] as $d => $cnt) {
                $totalsByDay[$d] = ($totalsByDay[$d] ?? 0) + (int)$cnt;
            }
        }
        ksort($totalsByDay); // stable
        $bestDayIso  = null;
        $worstDayIso = null;
        if (!empty($totalsByDay)) {
            // best: max total, earliest on tie
            $max = max($totalsByDay);
            foreach ($totalsByDay as $d => $sum) { if ($sum === $max) { $bestDayIso = $d; break; } }
            // worst: min total, earliest on tie
            $min = min($totalsByDay);
            foreach ($totalsByDay as $d => $sum) { if ($sum === $min) { $worstDayIso = $d; break; } }
        }

        $facts = [
            'week_start'  => $weekStart,
            'dates'       => $dates,                 // ISO strings
            'date_labels' => $dateLabels,            // ISO -> "Thu (Aug 28)"
            'best_day'    => $bestDayIso,            // ISO or null
            'worst_day'   => $worstDayIso,           // ISO or null
            'habits'      => $perHabit,              // bullets + suggestions by habit
        ];
        $factsJson = json_encode($facts, JSON_UNESCAPED_SLASHES);

        // ------- Prompt: rewrite ONLY from facts, no new math -------
        $prompt = <<<PROMPT
        Return STRICT JSON with exactly this schema:

        {
        "wins": string[],
        "stumbles": string[],
        "patterns": string[],
        "next_actions": [
            { "title": string, "why": string, "steps": string[], "effort": "low"|"medium"|"high" }
        ]
        }

        You are NOT allowed to invent or change any numbers, dates, or habit names.
        Use ONLY the information in FACTS below. Rephrase the provided bullet points clearly and kindly for regular users.

        Rules:
        - "wins": pick up to 2–3 positive bullets from FACTS.habits[*].bullets (those with "met or above") and rewrite naturally (include habit name + numbers).
        - "stumbles": pick up to 2–3 under-target bullets and rewrite naturally (include habit name + numbers).
        - "patterns": if FACTS.best_day or FACTS.worst_day exist, mention them using FACTS.date_labels[ISO] for display (e.g., "Thu (Aug 28)"). Keep sentences short.
        - "next_actions": choose up to 3 items from FACTS.habits[*].suggestions. You may rephrase titles/why/steps, but do NOT change their intent or introduce new numbers.
        - Never output a bare ISO date. If you mention a day, use FACTS.date_labels[ISO].
        - No preamble, no extra fields, JSON only.

        FACTS (do not change numbers/names):
        {$factsJson}

        User timezone: {$tz}. Week start (Mon): {$weekStart}.
        PROMPT;

        // ------- Call LLM -------
        $t0  = microtime(true);
        $out = $ai->chatJson($prompt, ['temperature' => 0.2]);
        $ms  = (int) ((microtime(true) - $t0) * 1000);

        // ------- Quality guard -------
        $coerceList = fn ($arr) => array_values(array_filter((array)$arr, fn ($s) => is_string($s) && trim($s) !== ''));

        $wins     = $coerceList($out['wins'] ?? []);
        $stumbles = $coerceList($out['stumbles'] ?? []);
        $patterns = $coerceList($out['patterns'] ?? []);
        $actions  = collect($out['next_actions'] ?? [])->take(3)->map(function ($a) {
            $effort = in_array(($a['effort'] ?? 'low'), ['low','medium','high']) ? $a['effort'] : 'low';
            return [
                'title'  => (string)($a['title'] ?? ''),
                'why'    => (string)($a['why'] ?? ''),
                'steps'  => array_values(array_filter((array)($a['steps'] ?? []), 'is_string')),
                'effort' => $effort,
            ];
        })->values()->all();

        $allEmpty = empty($wins) && empty($stumbles) && empty($patterns) && empty($actions);

        // If the model gives us nothing, synthesize a minimal but useful review from facts
        if ($allEmpty) {
            // Pick up to 2 wins from "met or above"
            $winLines = [];
            foreach ($perHabit as $h) {
                foreach ($h['bullets'] as $b) {
                    if (str_contains($b, 'met or above')) {
                        $winLines[] = $b;
                        if (count($winLines) >= 2) break 2;
                    }
                }
            }
            // Pick up to 3 stumbles from "under by"
            $stLines = [];
            foreach ($perHabit as $h) {
                foreach ($h['bullets'] as $b) {
                    if (str_contains($b, 'under by')) {
                        $stLines[] = $b;
                        if (count($stLines) >= 3) break 2;
                    }
                }
            }
            $wins     = $winLines ?: [];
            $stumbles = $stLines ?: [];
            if ($bestDayIso)  $patterns[] = "Best day: " . ($dateLabels[$bestDayIso] ?? $bestDayIso) . ".";
            if ($worstDayIso) $patterns[] = "Lightest day: " . ($dateLabels[$worstDayIso] ?? $worstDayIso) . ".";
            // Suggestions: first 3 available
            $actions = [];
            foreach ($perHabit as $h) {
                foreach ($h['suggestions'] as $s) {
                    $actions[] = $s;
                    if (count($actions) >= 3) break 2;
                }
            }
        }

        $data = [
            'wins'         => $wins,
            'stumbles'     => $stumbles,
            'patterns'     => $patterns,
            'next_actions' => $actions,
        ];

        // ------- Cache & log -------
        \Illuminate\Support\Facades\DB::transaction(function () use ($u, $weekStart, $data, $prompt, $out, $ms) {
            \App\Models\WeeklyReview::updateOrCreate(
                ['user_id' => $u->id, 'week_start' => $weekStart],
                ['payload' => $data]
            );
            \App\Models\AiCall::create([
                'user_id' => $u->id,
                'feature' => 'weekly_review',
                'input'   => ['prompt' => $prompt],
                'output'  => $out,
                'ms'      => $ms,
            ]);
        });

        return response()->json(['data' => $data, 'cached' => false]);
    }

    public function atomic(Request $r, AiClient $ai)
    {
        $data = $r->validate([
            'text' => 'required|string|min:3|max:200',
        ]);

        $prompt = <<<PROMPT
        Rewrite a vague goal into a tiny, daily, measurable habit.
        Return STRICT JSON:

        {
        "starter_goal": string,      // e.g., "Read 2 pages"
        "cue": string,               // e.g., "After breakfast"
        "duration_min": number,      // e.g., 5
        "location": string,          // e.g., "Sofa"
        "metric": string             // e.g., "pages"
        }

        Input: "{$data['text']}"
        Constraints:
        - Keep it small enough to do every day.
        - Use simple words.
        PROMPT;

        $t0 = microtime(true);
        $out = $ai->chatJson($prompt);
        $ms = (int)((microtime(true) - $t0) * 1000);

        $json = [
            'starter_goal' => (string)($out['starter_goal'] ?? ''),
            'cue' => (string)($out['cue'] ?? ''),
            'duration_min' => (int) max(1, (int)($out['duration_min'] ?? 5)),
            'location' => (string)($out['location'] ?? ''),
            'metric' => (string)($out['metric'] ?? ''),
        ];

        AiCall::create([
            'user_id' => $r->user()->id,
            'feature' => 'atomic_habit',
            'input' => ['text' => $data['text']],
            'output' => $json,
            'ms' => $ms,
        ]);

        return response()->json(['data' => $json]);
    }

    public function parseLog(Request $r, AiClient $ai)
    {
        $u = $r->user();
        $data = $r->validate([
            'message' => 'required|string|min:3|max:200',
        ]);

        // Small list of user habits (names) for better mapping
        $habits = Habit::where('user_id',$u->id)
            ->where('is_archived', false)
            ->get(['id','name']);

        $habitList = $habits->map(fn($h)=>"{$h->id}:{$h->name}")->implode(', ');

        $prompt = <<<PROMPT
        Parse the message into a habit log. Return STRICT JSON:

        {
        "habit_id_or_name": string,     // may be ID from the provided list or a name
        "count": number,
        "when": "morning"|"afternoon"|"evening"|"night"
        }

        Available habits (id:name): {$habitList}

        Message: "{$data['message']}"
        Rules:
        - If the name clearly matches one of the available habits, return its ID as habit_id_or_name (e.g., "5").
        - Else return the best name guess.
        - Count must be a positive integer. If none stated, return 1.
        - Infer "when" from words like breakfast (morning), lunch (afternoon), dinner (evening), night (night).
        PROMPT;

        $t0 = microtime(true);
        $out = $ai->chatJson($prompt, ['temperature' => 0.2]);
        $ms = (int)((microtime(true) - $t0) * 1000);

        $habitField = (string)($out['habit_id_or_name'] ?? '');
        $count = max(1, (int)($out['count'] ?? 1));
        $when = in_array(($out['when'] ?? ''), ['morning','afternoon','evening','night'])
            ? $out['when'] : 'evening';

        // Map habit_id_or_name to habit_id
        $habitId = null;
        if (ctype_digit($habitField)) {
            $habitId = (int)$habitField;
            if (!$habits->firstWhere('id', $habitId)) {
                $habitId = null;
            }
        }
        if (!$habitId && $habitField) {
            // fuzzy-ish: case-insensitive exact first
            $match = $habits->first(fn($h) => mb_strtolower($h->name) === mb_strtolower($habitField));
            if (!$match) {
                // startswith fallback
                $match = $habits->first(fn($h) => str_starts_with(mb_strtolower($h->name), mb_strtolower($habitField)));
            }
            if ($match) $habitId = $match->id;
        }

        AiCall::create([
            'user_id' => $u->id,
            'feature' => 'nl_log',
            'input' => ['message' => $data['message']],
            'output' => $out,
            'ms' => $ms,
        ]);

        return response()->json([
            'data' => [
                'habit_id' => $habitId,
                'fallback_name' => $habitId ? null : ($habitField ?: null),
                'count' => $count,
                'when'  => $when,
            ]
        ]);
    }
}