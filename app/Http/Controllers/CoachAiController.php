<?php

namespace App\Http\Controllers;

use App\Models\AiCall;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\WeeklyReview;
use App\Services\AiClient;
use Carbon\Carbon;
use DateInterval;
use DatePeriod;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CoachAiController extends Controller
{
    public function weekly(Request $r, AiClient $ai)
    {
        $u  = $r->user();
        $tz = $u->timezone ?: 'UTC';

        // ---- Resolve week window in user's timezone (Mon..Sun) ----
        $today     = \Carbon\Carbon::today($tz);
        $weekStart = $today->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->startOfDay();
        $weekEnd   = $today->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->endOfDay();

        $weekStartIso = $weekStart->toDateString(); // YYYY-MM-DD
        $weekEndIso   = $weekEnd->toDateString();

        // ---- Cache (bypass with ?refresh=1) ----
        $refresh = filter_var($r->query('refresh', '0'), FILTER_VALIDATE_BOOLEAN);
        if (!$refresh) {
            if ($cached = \App\Models\WeeklyReview::where('user_id', $u->id)->where('week_start', $weekStartIso)->first()) {
                return response()->json(['data' => $cached->payload, 'cached' => true]);
            }
        }

        // ---- Load active habits ----
        $habits = \App\Models\Habit::where('user_id', $u->id)
            ->where('is_archived', false)
            ->get(['id', 'name', 'target_per_day']);

        if ($habits->isEmpty()) {
            $empty = ['wins'=>[], 'stumbles'=>[], 'patterns'=>[], 'next_actions'=>[]];
            \App\Models\WeeklyReview::updateOrCreate(
                ['user_id' => $u->id, 'week_start' => $weekStartIso],
                ['payload' => $empty]
            );
            return response()->json(['data' => $empty, 'cached' => false]);
        }

        // ---- Fetch logs bounded to THIS week (inclusive) ----
        $logs = \App\Models\HabitLog::where('user_id', $u->id)
            ->whereIn('habit_id', $habits->pluck('id'))
            ->whereDate('log_date', '>=', $weekStartIso)
            ->whereDate('log_date', '<=', $weekEndIso)
            ->orderBy('log_date')
            ->get(['habit_id', 'log_date', 'count']);

        // ---- Build week date keys (Mon..Sun) ----
        $period = new \DatePeriod(
            new \DateTime($weekStartIso),
            new \DateInterval('P1D'),
            (new \DateTime($weekEndIso))->modify('+1 day')
        );
        $weekDates = [];
        foreach ($period as $d) { $weekDates[] = $d->format('Y-m-d'); }

        // ---- Labels in user's TZ (e.g., "Thu (Aug 28)") ----
        $dateLabels = [];
        foreach ($weekDates as $d) {
            $dateLabels[$d] = \Carbon\Carbon::parse($d, $tz)->isoFormat('ddd (MMM D)');
        }

        // ---- Build per-habit week-aligned series + tracked stats ----
        $perHabit = [];
        foreach ($habits as $h) {
            $target = max(1, (int)($h->target_per_day ?? 1));

            // Map existing entries for the week (date => count), keep set of tracked dates
            $seriesMap = $logs->where('habit_id', $h->id)
                ->mapWithKeys(function ($r) {
                    $iso = $r->log_date instanceof \DateTimeInterface
                        ? $r->log_date->toDateString()
                        : (string)$r->log_date;
                    return [$iso => (int)$r->count];
                })->all();
            $trackedDates = array_keys($seriesMap); // any row present (even count=0) is tracked

            // Build Mon..Sun vector; track only days with entries
            $weekSeries  = [];
            $trackedDays = 0;
            $metDays     = 0;
            $underDays   = 0; // includes zeros when tracked

            foreach ($weekDates as $dk) {
                $hasEntry = array_key_exists($dk, $seriesMap);
                $cnt      = $hasEntry ? (int)$seriesMap[$dk] : 0;

                if ($hasEntry) {
                    $trackedDays++;
                    if ($cnt >= $target) {
                        $metDays++;
                    } else {
                        // ✅ treat zero (and any < target) as under when the day is tracked
                        $underDays++;
                    }
                }
                $weekSeries[$dk] = $cnt;
            }

            // Last 3 *tracked* entries (chronological)
            $trackedOnly = array_filter(
                $seriesMap,
                fn($v, $k) => in_array($k, $weekDates, true),
                ARRAY_FILTER_USE_BOTH
            );
            ksort($trackedOnly);
            $last3 = array_slice($trackedOnly, -3, 3, true);

            // Numeric bullets (only for tracked entries)
            $bullets = [];
            foreach ($last3 as $d => $cnt) {
                $label = $dateLabels[$d] ?? $d;
                if ($cnt >= $target) {
                    $bullets[] = "{$h->name}: {$cnt}/{$target} on {$label} (met or above)";
                } else {
                    $bullets[] = "{$h->name}: {$cnt}/{$target} on {$label} (under by " . ($target - $cnt) . ")";
                }
            }

            $perHabit[] = [
                'name'          => (string)$h->name,
                'target'        => $target,
                'week_series'   => $weekSeries,     // Mon..Sun counts (0 for no entry)
                'tracked_dates' => $trackedDates,   // explicit tracked dates for this habit
                'tracked_days'  => $trackedDays,
                'met_days'      => $metDays,
                'under_days'    => $underDays,
                'last3'         => $last3,
                'bullets'       => $bullets,
            ];
        }

        // ---- If NO tracked data at all, return a minimal but helpful review (no AI) ----
        $totalTracked = array_sum(array_map(fn($h) => $h['tracked_days'], $perHabit));
        if ($totalTracked === 0) {
            $empty = [
                'wins'     => [],
                'stumbles' => [],
                'patterns' => [],
                'next_actions' => [[
                    'title'  => 'Start tracking today',
                    'why'    => 'No entries this week yet, so we can’t evaluate progress.',
                    'steps'  => [
                        'Open the app and log your first entry today',
                        'Optionally set a daily reminder',
                        'Come back tomorrow to see your weekly insights',
                    ],
                    'effort' => 'low',
                ]],
            ];
            \App\Models\WeeklyReview::updateOrCreate(
                ['user_id' => $u->id, 'week_start' => $weekStartIso],
                ['payload' => $empty]
            );
            return response()->json(['data' => $empty, 'cached' => false]);
        }

        // ---- Best/Worst across habits (tracked days only) ----
        // A day is "tracked" if ANY habit has an entry for that date (even if count==0).
        $totalsByDay      = array_fill_keys($weekDates, 0);
        $trackedFlagByDay = array_fill_keys($weekDates, false);

        foreach ($perHabit as $h) {
            $trackedSet = array_flip($h['tracked_dates'] ?? []);
            foreach ($h['week_series'] as $d => $cnt) {
                if (isset($trackedSet[$d])) {
                    $trackedFlagByDay[$d] = true;
                    $totalsByDay[$d] += (int)$cnt;
                }
            }
        }

        // Keep only tracked days
        $trackedTotals = [];
        foreach ($weekDates as $d) {
            if ($trackedFlagByDay[$d]) {
                $trackedTotals[$d] = $totalsByDay[$d];
            }
        }

        $bestDayIso  = null;
        $worstDayIso = null;

        if (!empty($trackedTotals)) {
            // ✅ Best day = max total strictly > 0 (avoid picking a zero day as "best")
            $max = max($trackedTotals);
            if ($max > 0) {
                foreach ($weekDates as $d) {
                    if (($trackedTotals[$d] ?? null) === $max) { $bestDayIso = $d; break; }
                }
            }

            // ✅ Worst day = min total (including 0). Earliest wins on ties.
            $min = min($trackedTotals);
            foreach ($weekDates as $d) {
                if (($trackedTotals[$d] ?? null) === $min) { $worstDayIso = $d; break; }
            }
        }

        // ---- Facts for LLM (strings only; no invented math) ----
        $facts = [
            'week_start'  => $weekStartIso,
            'week_end'    => $weekEndIso,
            'date_labels' => $dateLabels,    // ISO -> "Thu (Aug 28)"
            'habits'      => $perHabit,      // includes tracked_days/met_days/under_days/last3/bullets
            'best_day'    => $bestDayIso,    // ISO or null (only if >0)
            'worst_day'   => $worstDayIso,   // ISO or null (can be 0)
        ];
        $factsJson = json_encode($facts, JSON_UNESCAPED_SLASHES);

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

        Rules:
        - Only refer to “met” or “not met” using tracked days:
        - met_days = days with entries count >= target
        - under_days = days with entries count < target (including 0)
        - Do NOT count untracked days as “not met”.
        - If a habit has tracked_days = 0, do NOT include it in wins/stumbles.
        - "wins": pick up to 3 positive bullets from habits[*].bullets that indicate "met or above", rewrite kindly with habit name + numbers.
        - "stumbles": pick up to 3 under-target bullets (those with "under by ..."), rewrite kindly with habit name + numbers.
        - "patterns": if best_day or worst_day exist, mention them using date_labels[ISO]. Keep sentences short. Reflect EXACTLY the values of best_day/worst_day from FACTS; if either is null, omit it.
        - "next_actions": up to 3 practical actions based on the facts—e.g., suggest reminders if last entries are within 1 of target, or small target adjustment if under_days are prevalent. Do NOT invent numbers or dates.
        - Never output a bare ISO date; always use date_labels.

        FACTS (do not change numbers/names):
        {$factsJson}

        User timezone: {$tz}. Week window: {$weekStartIso}..{$weekEndIso} (Mon..Sun).
        PROMPT;

        // ---- Call LLM safely ----
        $t0  = microtime(true);
        $out = $ai->chatJson($prompt, ['temperature' => 0.2]);
        $ms  = (int)((microtime(true) - $t0) * 1000);

        // ---- Coerce & guard output ----
        $strList = fn ($arr) => array_values(array_filter((array)$arr, fn ($s) => is_string($s) && trim($s) !== ''));
        $wins     = $strList($out['wins'] ?? []);
        $stumbles = $strList($out['stumbles'] ?? []);
        $patterns = $strList($out['patterns'] ?? []);
        $actions  = collect($out['next_actions'] ?? [])->take(3)->map(function ($a) {
            $effort = in_array(($a['effort'] ?? 'low'), ['low','medium','high']) ? $a['effort'] : 'low';
            return [
                'title'  => (string)($a['title'] ?? ''),
                'why'    => (string)($a['why'] ?? ''),
                'steps'  => array_values(array_filter((array)($a['steps'] ?? []), 'is_string')),
                'effort' => $effort,
            ];
        })->values()->all();

        // ---- Fallback: minimal but precise from facts if the model failed ----
        $allEmpty = empty($wins) && empty($stumbles) && empty($patterns) && empty($actions);
        if ($allEmpty) {
            // pick up to 2 wins and 3 stumbles from bullets
            $winLines = []; $stLines = [];
            foreach ($perHabit as $h) {
                foreach ($h['bullets'] as $b) {
                    if (str_contains($b, 'met or above')) { $winLines[] = $b; if (count($winLines) >= 2) break; }
                }
            }
            foreach ($perHabit as $h) {
                foreach ($h['bullets'] as $b) {
                    if (str_contains($b, 'under by')) { $stLines[] = $b; if (count($stLines) >= 3) break; }
                }
            }
            $wins     = $winLines;
            $stumbles = $stLines;
            if ($bestDayIso)  { $patterns[] = "Best day: " . ($dateLabels[$bestDayIso] ?? $bestDayIso) . "."; }
            if ($worstDayIso) { $patterns[] = "Lightest day: " . ($dateLabels[$worstDayIso] ?? $worstDayIso) . "."; }
            $hasUnder = array_sum(array_column($perHabit, 'under_days')) > 0;
            $actions = [[
                'title'  => $hasUnder ? 'Add a reminder or adjust target slightly' : 'Keep momentum',
                'why'    => $hasUnder ? 'Some entries were below target.' : 'You have a good baseline this week.',
                'steps'  => $hasUnder
                    ? ['Open the habit', 'Enable a reminder at a convenient time', 'Try a small target adjustment (10–20%) for 7 days']
                    : ['Continue logging daily', 'Review next week for trends'],
                'effort' => 'low',
            ]];
        }

        $data = [
            'wins'         => $wins,
            'stumbles'     => $stumbles,
            'patterns'     => $patterns,
            'next_actions' => $actions,
        ];

        // ---- Cache + log atomically ----
        \Illuminate\Support\Facades\DB::transaction(function () use ($u, $weekStartIso, $data, $prompt, $out, $ms) {
            \App\Models\WeeklyReview::updateOrCreate(
                ['user_id' => $u->id, 'week_start' => $weekStartIso],
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

        // Only include heavy debug fields when APP_DEBUG=true
        $debug = app()->has('config') && config('app.debug');

        return response()->json(array_filter([
            'data'   => $data,
            'cached' => false,
            $debug ? 'prompt' : null => $debug ? $prompt : null,
            $debug ? 'out'    : null => $debug ? $out    : null,
        ]));
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