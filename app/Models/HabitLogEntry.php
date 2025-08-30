<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HabitLogEntry extends Model
{
    protected $fillable = ['habit_log_id', 'logged_at'];

    protected $casts = [
        'logged_at' => 'datetime',
    ];

    public function log() { return $this->belongsTo(HabitLog::class, 'habit_log_id'); }
}
