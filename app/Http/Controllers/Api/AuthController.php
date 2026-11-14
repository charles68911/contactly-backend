<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    // REGISTER
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        try {
            // Create the user and issue the Sanctum token atomically.
            [$user, $token] = DB::transaction(function () use ($validated) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    // Public registration can never assign the admin role.
                    'role' => User::ROLE_USER,
                ]);

                $token = $user->createToken('CONTACTLY')->plainTextToken;

                return [$user, $token];
            });
        } catch (QueryException $e) {
            // Handles the race where two requests register the same email
            // between validation and insert.
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'email' => ['The email has already been taken.'],
                ]);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Account registered successfully.',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    // LOGIN
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        $token = $user->createToken('CONTACTLY')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    // LOGOUT
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        // Bearer-token sessions revoke only this token. Session-based
        // authentication has no personal access token to delete.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }
}
