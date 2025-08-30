<?php

namespace App\Http\Controllers;

use App\Models\CoachSuggestion;
use App\Models\Habit;
use App\Services\CoachService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CoachController extends Controller
{
    public function __construct(private CoachService $service) {}

    public function index(Request $req)
    {
        $user = $req->user();
        $suggestions = $this->service->generateForUser($user->id);
        return response()->json(['data' => $suggestions]);
    }

    public function accept(Request $req, int $id)
    {
        $s = CoachSuggestion::where('id',$id)
            ->where('user_id', $req->user()->id)
            ->where('status','pending')->firstOrFail();

        // Apply effect if it’s an ADJUST suggestion with payload.
        if ($s->habit_id && $s->type === 'adjust' && is_array($s->payload)) {
            $habit = Habit::where('id',$s->habit_id)->where('user_id',$req->user()->id)->first();
            if ($habit) {
                if (isset($s->payload['suggest_target'])) {
                    $habit->target_per_day = max(1, (int)$s->payload['suggest_target']);
                }
                if (isset($s->payload['suggest_time'])) {
                    // store a custom field on habits if you have it (e.g., preferred_time)
                    $habit->preferred_time = $s->payload['suggest_time']; // add column if needed
                }
                $habit->save();
            }
        }

        $s->status = 'accepted';
        $s->save();

        return response()->json(['message' => 'accepted']);
    }

    public function dismiss(Request $req, int $id)
    {
        $s = CoachSuggestion::where('id',$id)
            ->where('user_id', $req->user()->id)
            ->where('status','pending')->firstOrFail();

        $s->status = 'dismissed';
        $s->save();

        return response()->json(['message' => 'dismissed']);
    }
}
