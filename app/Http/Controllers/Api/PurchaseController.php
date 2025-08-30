<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function confirm(Request $request) {
        $data = $request->validate([
            'provider' => 'required|in:stripe,gumroad,paddle,manual',
            'product_code' => 'required|string|max:100',
            'provider_txn_id' => 'nullable|string|max:191',
            'amount_cents' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|max:10',
            'payload' => 'nullable|array',
        ]);

        // TODO: verify with provider webhooks/SDK in production.
        // For MVP: accept, save, mark user premium.
        return DB::transaction(function () use ($request, $data) {
            $purchase = Purchase::create(array_merge($data, [
                'user_id' => $request->user()->id,
                'status' => 'paid',
                'purchased_at' => now(),
            ]));

            $user = $request->user();
            $user->is_premium = true;
            $user->save();

            return response()->json([
                'message' => 'Premium unlocked',
                'purchase' => $purchase,
                'user' => ['is_premium' => $user->is_premium],
            ]);
        });
    }
}
