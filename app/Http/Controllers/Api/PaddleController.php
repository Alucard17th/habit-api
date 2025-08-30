<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;

class PaddleController extends Controller
{
    //
    public function checkout(Request $request)
    {
        try{
            $priceId = 'pri_01k3q682sqw3hyfz9ez1fz3w6v';
            $checkout = $request->user()
            ->checkout($priceId); // your Paddle price ID (one-time)
            // ->returnTo(route('billing.success')); // where Paddle should redirect

            // For a SPA, return options to open with Paddle.Checkout.open(...)
            // Get options array Cashier returns for Paddle.js
            $options = $checkout->options();

            // Add signed custom data
            $nonce = (string) Str::uuid();
            $message = $request->user()->id.'|'.$priceId.'|'.$nonce;
            $sig = hash_hmac('sha256', $message, config('app.key'));

            $options['customData'] = [
                'user_id' => $request->user()->id,
                'price_id' => $priceId,
                'nonce' => $nonce,
                'sig' => $sig,
            ];
            return response()->json($options);
        }catch(\Exception $e){
            return response()->json(['error' => $e->getMessage()]);
        }
    }
}
