<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachSuggestion extends Model {
    protected $fillable = [
        'user_id','habit_id','type','code','title','message','payload','status','valid_until'
    ];
    protected $casts = ['payload' => 'array', 'valid_until' => 'datetime'];
}
