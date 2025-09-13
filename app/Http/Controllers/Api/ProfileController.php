<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * Return the authenticated user.
     */
    public function show(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Update profile fields (name, email).
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        // If email is changed, you may wish to reset verification
        if ($validated['email'] !== $user->email && \Schema::hasColumn('users', 'email_verified_at')) {
            $user->email_verified_at = null;
        }

        $user->fill($validated)->save();

        return response()->json($user->fresh());
    }

    /**
     * Update password (requires current password).
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password'       => ['required', 'current_password'], // uses default auth guard
            'password'               => ['required', 'string', 'min:8', 'confirmed'],
            // expects password_confirmation field
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        // (Optional but recommended) Invalidate other tokens/sessions:
        // - If using Sanctum: delete all tokens except current
        if (method_exists($user, 'tokens')) {
            $currentAccessTokenId = optional($request->user()->currentAccessToken())->id;
            $user->tokens()
                ->when($currentAccessTokenId, fn($q) => $q->where('id', '!=', $currentAccessTokenId))
                ->delete();
        }

        return response()->json(['message' => 'Password updated']);
    }

    public function verifyLicense(Request $request)
    {
        $data = $request->validate([
            'license_key' => 'required|string',
            // 'product_id'  => 'nullable|string',
        ]);

        $licenseKey = trim($data['license_key']);
        $productId  = $data['product_id'] ?? env('GUMROAD_PRODUCT_ID', '');
        $verifyUrl  = 'https://api.gumroad.com/v2/licenses/verify';

        // Build POST fields (omit product_id if empty)
        $postFields = ['license_key' => $licenseKey];
        if (!empty($productId)) {
            $postFields['product_id'] = $productId;
        }

        // Prepare CURL
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $verifyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => http_build_query($postFields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($curl);
        $error    = curl_error($curl);
        curl_close($curl);

        // If CURL failed completely
        if ($error) {
            return response()->json([
                'ok'     => false,
                'error'  => 'curl_failed',
                'detail' => $error,
            ], 502);
        }

        // Decode Gumroad response
        $body = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'ok'    => false,
                'error' => 'invalid_json',
                'raw'   => $response,
            ], 500);
        }

        // Handle Gumroad error shape: { success: false, message: "..." }
        if (!($body['success'] ?? false)) {
            return response()->json([
                'ok'      => false,
                'error'   => 'invalid_license',
                'message' => $body['message'] ?? 'License verification failed.',
                'gumroad' => $body,
            ], 400);
        }

        // success: true path
        $purchase = $body['purchase'] ?? [];

        // If product_id was supplied, ensure it matches what Gumroad says
        if (!empty($productId) && ($purchase['product_id'] ?? null) !== $productId) {
            return response()->json([
                'ok'      => false,
                'error'   => 'product_mismatch',
                'message' => 'The license is not valid for the provided product.',
                'gumroad' => $body,
            ], 400);
        }

        // Check refunds/chargebacks
        if (($purchase['refunded'] ?? false) || ($purchase['chargebacked'] ?? false)) {
            return response()->json([
                'ok'      => false,
                'error'   => 'revoked_license',
                'message' => 'This license has been refunded or chargebacked.',
                'gumroad' => $body,
            ], 403);
        }

        // (Optional) You may want to disallow test purchases in production
        $isTest = (bool)($purchase['test'] ?? false);
        $allowTest = (bool)env('GUMROAD_ALLOW_TEST', true); // set to false in prod if desired
        if ($isTest && !$allowTest) {
            return response()->json([
                'ok'      => false,
                'error'   => 'test_purchase_not_allowed',
                'message' => 'Test-mode purchases are not allowed.',
                'gumroad' => $body,
            ], 403);
        }

        // ✅ Upgrade the authenticated user to premium
        $user = $request->user();
        if ($user) {
            $user->forceFill(['is_premium' => true])->save();
        }

        // Prepare a clean success payload (mask license key)
        $maskedKey = substr($licenseKey, 0, 4) . str_repeat('*', max(0, strlen($licenseKey) - 8)) . substr($licenseKey, -4);

        return response()->json([
            'ok'       => true,
            'message'  => 'License verified and premium unlocked.',
            'plan'     => $user?->plan ?? 'premium',
            'uses'     => $body['uses'] ?? null,
            'license'  => [
                'masked_key'     => $maskedKey,
                'product_id'     => $purchase['product_id'] ?? null,
                'product_name'   => $purchase['product_name'] ?? null,
                'permalink'      => $purchase['permalink'] ?? null,
                'product_link'   => $purchase['product_permalink'] ?? null,
                'email'          => $purchase['email'] ?? null,
                'order_number'   => $purchase['order_number'] ?? null,
                'sale_id'        => $purchase['sale_id'] ?? null,
                'sale_timestamp' => $purchase['sale_timestamp'] ?? null,
                'price'          => $purchase['price'] ?? null,
                'currency'       => $purchase['currency'] ?? null,
                'test'           => $isTest,
            ],
            // Keep full body for debugging if you want (or drop in production)
            'gumroad'  => $body,
        ]);
    }


}
