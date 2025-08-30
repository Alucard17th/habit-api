<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCall extends Model
{
    protected $fillable = ['user_id','feature','input','output','ms'];
    protected $casts = ['input' => 'array', 'output' => 'array'];
}
