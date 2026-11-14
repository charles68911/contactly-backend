<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordChangeController extends Controller
{
    /**
     * Change the authenticated user's password directly.
     *
     * The current password is verified by UpdatePasswordRequest through
     * Laravel's current_password rule, which uses Hash::check under the
     * Sanctum guard. Only the authenticated user's own password is updated.
     */
    public function update(UpdatePasswordRequest $request)
    {
        $user = $request->user();

        // Keep the device that performed the change signed in. Any other
        // Sanctum tokens belonging to this user are revoked so a compromised
        // session cannot outlive the password change.
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken
            ? $currentToken->getKey()
            : null;

        DB::transaction(function () use ($request, $user, $currentTokenId) {
            $user->forceFill([
                'password' => Hash::make($request->validated('password')),
            ])->save();

            $tokens = $user->tokens();

            if ($currentTokenId !== null) {
                $tokens->whereKeyNot($currentTokenId);
            }

            $tokens->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }
}
