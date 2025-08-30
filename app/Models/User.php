<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Paddle\Billable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable, Billable;

    protected $fillable = ['name','email','password','is_premium', 'trial_ends_at', 'paddle_id', 'paddle_status'];
    protected $hidden   = ['password','remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_premium' => 'boolean',
        'trial_ends_at' => 'datetime',
    ];

    public function habits()  { return $this->hasMany(Habit::class); }
    public function purchases(){ return $this->hasMany(Purchase::class); }

    public function getIsProAttribute(): bool { return (bool)$this->is_premium; }
}
