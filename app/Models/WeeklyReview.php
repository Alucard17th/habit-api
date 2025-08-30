<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeeklyReview extends Model
{
    protected $fillable = ['user_id','week_start','payload'];
    protected $casts = ['week_start' => 'date', 'payload' => 'array'];
}
