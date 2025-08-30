<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HabitLog extends Model
{
    protected $fillable = ['habit_id','user_id','log_date','count'];

    protected $casts = [
        'log_date' => 'date',
        'count' => 'integer',
    ];

    public function habit() { return $this->belongsTo(Habit::class); }
    public function user()  { return $this->belongsTo(User::class); }
    public function entries() { return $this->hasMany(HabitLogEntry::class); }
}
