<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;

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
}
