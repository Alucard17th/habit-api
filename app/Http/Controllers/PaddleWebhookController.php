<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Laravel\Paddle\Http\Controllers\WebhookController as CashierPaddleWebhook;
use App\Models\User;

class PaddleWebhookController extends CashierPaddleWebhook
{

    public function __invoke(Request $request)
    {
        \Log::info('[Paddle] Webhook received', $request->all());
        return parent::__invoke($request);
    }
    // public function handleWebhook(array $payload)
    // {
    //     switch ($payload['event']) {
    //         case 'subscription.activated':
    //             return $this->handleSubscriptionActivated($payload);
    //         case 'subscription.payment_succeeded':
    //             return $this->handleSubscriptionPaymentSucceeded($payload);
    //         case 'transaction.completed':
    //             return $this->handleTransactionCompleted($payload);
    //         case 'subscription.canceled':
    //             return $this->handleSubscriptionCanceled($payload);
    //         default:
    //             return new Response('Webhook Handled: unknown event', 200);
    //     }
    // }
    /**
     * Subscription activated (first successful payment).
     */
    protected function handleSubscriptionActivated(array $payload)
    {
        $this->markUserProFromPayload($payload, true);
        return new Response('Webhook Handled: subscription.activated', 200);
    }

    /**
     * Recurring payment succeeded.
     */
    protected function handleSubscriptionPaymentSucceeded(array $payload)
    {
        $this->markUserProFromPayload($payload, true);
        return new Response('Webhook Handled: subscription.payment_succeeded', 200);
    }

    /**
     * One-time transaction completed (lifetime/one-off).
     */
    protected function handleTransactionCompleted(array $payload)
    {
        $this->markUserProFromPayload($payload, true);
        return new Response('Webhook Handled: transaction.completed', 200);
    }

    /**
     * Subscription canceled / expired → remove Pro.
     */
    protected function handleSubscriptionCanceled(array $payload)
    {
        $this->markUserProFromPayload($payload, false);
        return new Response('Webhook Handled: subscription.canceled', 200);
    }

    /**
     * Helper: look up the user by Paddle customer id and toggle is_premium.
     */
    private function markUserProFromPayload(array $payload, bool $pro): void
    {
        $cd = data_get($payload, 'data.custom_data')      // Paddle Billing
        ?? data_get($payload, 'event_data.custom_data'); // defensive

        $userId  = data_get($cd, 'user_id');
        $priceId = data_get($cd, 'price_id');
        $nonce   = data_get($cd, 'nonce');
        $sig     = data_get($cd, 'sig');

        if (!$userId || !$priceId || !$nonce || !$sig) {
            \Log::warning('[Paddle] Missing custom_data fields', compact('userId','priceId','nonce'));
            return;
        }

        $message = $userId.'|'.$priceId.'|'.$nonce;
        $calcSig = hash_hmac('sha256', $message, config('app.key'));
        if (!hash_equals($sig, $calcSig)) {
            \Log::warning('[Paddle] Invalid custom_data signature');
            return;
        }

        // Now it’s safe to trust user_id
        $user = \App\Models\User::find($userId);
        if (!$user) {
            \Log::warning('[Paddle] User not found for user_id='.$userId);
            return;
        }

        // Optional: also use the webhook’s customer_id to backfill paddle_id
        $customerId = data_get($payload, 'data.customer_id')
                ?? data_get($payload, 'event_data.customer_id');
        if ($customerId && !$user->paddle_id) {
            $user->paddle_id = $customerId;
        }

        $user->is_premium = $pro;
        if ($status = data_get($payload, 'data.status') ?? data_get($payload, 'event_data.status')) {
            $user->paddle_status = $status;
        }
        $user->save();
    }

}
