<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Habit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id','name','frequency','target_per_day','reminder_time',
        'streak_current','streak_longest','last_completed_date','is_archived'
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'reminder_time' => 'datetime:H:i',
        'last_completed_date' => 'date',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function logs() { return $this->hasMany(HabitLog::class); }

    public function scopeActive($q) {
        return $q->where('is_archived', false);
    }
}
