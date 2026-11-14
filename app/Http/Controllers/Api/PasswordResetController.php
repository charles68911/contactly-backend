<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Support\MailDiagnostics;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetController extends Controller
{
    /**
     * Email a password reset link, without revealing whether the account exists.
     */
    public function sendResetLink(ForgotPasswordRequest $request)
    {
        // Refuse log/array transports outside tests: they do not deliver mail
        // and would write the single-use reset token into a log file.
        if (! app()->environment('testing') && MailDiagnostics::isNonDeliveringMailer()) {
            return response()->json([
                'success' => false,
                'message' => 'Email delivery is not configured. Set MAIL_MAILER=smtp and the Gmail credentials before requesting a password reset.',
            ], 503);
        }

        try {
            Password::sendResetLink($request->validated());
        } catch (Throwable $exception) {
            // Keep the response generic so a mail outage cannot be used to
            // enumerate accounts. The token is removed so a stale link does
            // not linger, and the real error is logged for operators.
            DB::table('password_reset_tokens')
                ->where('email', $request->validated('email'))
                ->delete();

            MailDiagnostics::logFailure($exception, [
                'flow' => 'password_forgot',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account exists with that email address, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset a password using a valid, single-use broker token.
     */
    public function reset(ResetPasswordRequest $request)
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                // A password reset signs the user out everywhere.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully. Please log in with your new password.',
            ]);
        }

        throw ValidationException::withMessages([
            'token' => [__($status)],
        ]);
    }
}
