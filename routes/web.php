<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaddleWebhookController;

Route::get('/', function () {
    return view('welcome');
});
Route::post('/paddle/webhook', PaddleWebhookController::class);
