<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompleteAccountRecoveryRequest;
use App\Http\Requests\Auth\VerifyAccountRecoveryRequest;
use App\Models\AccountRecoveryToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountRecoveryController extends Controller
{
    /**
     * Number of minutes a recovery token remains valid.
     */
    private const TOKEN_TTL_MINUTES = 10;

    /**
     * Verify that the email address and account ID belong to the same user,
     * then issue a short-lived single-use recovery token.
     */
    public function verify(VerifyAccountRecoveryRequest $request)
    {
        $validated = $request->validated();

        $user = User::query()
            ->whereKey($validated['account_id'])
            ->where('email', $validated['email'])
            ->first();

        // Use the same generic response for a missing account, a wrong email,
        // and a wrong account ID so neither value can be probed independently.
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Account verification failed. The email and account ID do not match an account.',
            ], 422);
        }

        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);

        DB::transaction(function () use ($user, $plainToken, $expiresAt) {
            // A new verification supersedes any previous recovery tokens.
            AccountRecoveryToken::where('user_id', $user->id)->delete();

            AccountRecoveryToken::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => $expiresAt,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Account verified. Use this token to reset your password.',
            'token' => $plainToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Reset the password for the user that owns the recovery token.
     */
    public function reset(CompleteAccountRecoveryRequest $request)
    {
        $tokenHash = hash('sha256', $request->validated('token'));
        $newPassword = $request->validated('password');

        DB::transaction(function () use ($tokenHash, $newPassword) {
            $record = AccountRecoveryToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($record === null || ! $record->isUsable() || $record->user === null) {
                throw ValidationException::withMessages([
                    'token' => ['The recovery token is invalid, expired, or has already been used.'],
                ]);
            }

            $user = $record->user;

            $user->forceFill([
                'password' => Hash::make($newPassword),
            ])->save();

            // Consume the token and discard any others for this user.
            $record->forceFill(['used_at' => now()])->save();

            AccountRecoveryToken::where('user_id', $user->id)
                ->whereKeyNot($record->getKey())
                ->delete();

            // Account recovery ends any existing API sessions.
            $user->tokens()->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.',
        ]);
    }
}
