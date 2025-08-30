<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = [
        'user_id','provider','product_code','provider_txn_id',
        'amount_cents','currency','status','purchased_at','payload'
    ];

    protected $casts = [
        'purchased_at' => 'datetime',
        'payload' => 'array',
    ];

    public function user(){ return $this->belongsTo(User::class); }
}

