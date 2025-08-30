<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Habit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function summary(Request $request) {
        $userId = $request->user()->id;
        $from = $request->query('from'); // Y-m-d
        $to   = $request->query('to');

        $rows = DB::table('habit_logs')
            ->selectRaw('habit_id, log_date, count')
            ->where('user_id', $userId)
            ->when($from, fn($q) => $q->whereDate('log_date','>=',$from))
            ->when($to,   fn($q) => $q->whereDate('log_date','<=',$to))
            ->orderBy('log_date','asc')
            ->get();

        return response()->json($rows);
    }
}
