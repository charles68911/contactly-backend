<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserManagementController extends Controller
{
    /**
     * List all users with optional search and pagination.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim($validated['search'] ?? '');
        $perPage = (int) ($validated['per_page'] ?? 15);

        $users = User::query()
            ->withCount('contacts')
            ->when($search !== '', function ($query) use ($search) {
                // whereLike() keeps the search case-insensitive on every
                // driver: MySQL uses its CI collation, PostgreSQL uses ILIKE.
                $query->where(function ($q) use ($search) {
                    $q->whereLike('name', "%{$search}%")
                        ->orWhereLike('email', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return UserResource::collection($users)
            ->additional(['success' => true]);
    }

    /**
     * Show a single user account.
     */
    public function show(User $user)
    {
        $user->loadCount('contacts');

        return (new UserResource($user))
            ->additional(['success' => true]);
    }

    /**
     * Update a user's name, email address, or role.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $validated = $request->validated();

        // Never allow the final administrator to be demoted.
        if (
            isset($validated['role'])
            && $validated['role'] !== User::ROLE_ADMIN
            && $user->isAdmin()
            && User::where('role', User::ROLE_ADMIN)->count() <= 1
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The last administrator cannot be demoted.',
            ], 422);
        }

        $user->fill($validated)->save();

        return (new UserResource($user->fresh()->loadCount('contacts')))
            ->additional([
                'success' => true,
                'message' => 'User updated successfully.',
            ]);
    }

    /**
     * Delete a user account and its related records.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'The last administrator cannot be deleted.',
            ], 422);
        }

        DB::transaction(function () use ($user) {
            // Remove the user's contacts explicitly, then revoke tokens so no
            // orphaned access tokens remain after the account is gone.
            $user->contacts()->delete();
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Set a new password for a user and revoke their existing tokens.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user)
    {
        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
        ])->save();

        // Force the affected user to authenticate again everywhere.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Existing sessions have been revoked.',
        ]);
    }
}
