<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_ADMIN,
        ], $attributes));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-suite')->plainTextToken;
    }

    private function actingWithToken(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user));
    }

    /**
     * Laravel's auth guards cache the resolved user for the lifetime of a
     * test, so reset them before asserting that a revoked token fails.
     */
    private function forgetCachedGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_admin_can_list_users_with_pagination(): void
    {
        $admin = $this->admin();

        User::factory()->count(17)->create(['role' => User::ROLE_USER]);

        $response = $this->actingWithToken($admin)
            ->getJson('/api/admin/users?per_page=5');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 18)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'email', 'role', 'created_at', 'updated_at'],
                ],
                'links',
                'meta',
            ])
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.tokens');
    }

    public function test_admin_can_search_users_by_name_and_email(): void
    {
        $admin = $this->admin(['name' => 'Root Admin', 'email' => 'root@example.com']);

        User::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
        User::factory()->create(['name' => 'Bob Jones', 'email' => 'bob@example.com']);

        $this->actingWithToken($admin)
            ->getJson('/api/admin/users?search=Alice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alice Smith');

        $this->actingWithToken($admin)
            ->getJson('/api/admin/users?search=bob@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'bob@example.com');
    }

    public function test_admin_can_view_a_specific_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        Contact::create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $this->actingWithToken($admin)
            ->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.role', User::ROLE_USER)
            ->assertJsonPath('data.contacts_count', 1)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_admin_can_update_a_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingWithToken($admin)
            ->putJson("/api/admin/users/{$user->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
                'role' => User::ROLE_ADMIN,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@example.com')
            ->assertJsonPath('data.role', User::ROLE_ADMIN)
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingWithToken($admin)
            ->putJson("/api/admin/users/{$user->id}", ['role' => User::ROLE_USER])
            ->assertOk()
            ->assertJsonPath('data.role', User::ROLE_USER);
    }

    public function test_admin_cannot_set_a_duplicate_email(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create(['email' => 'first@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingWithToken($admin)
            ->putJson("/api/admin/users/{$first->id}", ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('users', [
            'id' => $first->id,
            'email' => 'first@example.com',
        ]);
    }

    public function test_admin_can_delete_a_user_and_their_contacts(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $contact = Contact::create([
            'user_id' => $user->id,
            'first_name' => 'Delete',
            'last_name' => 'Me',
        ]);

        $this->actingWithToken($admin)
            ->deleteJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);

        $this->forgetCachedGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingWithToken($admin)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You cannot delete your own account.');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_last_administrator_cannot_be_deleted(): void
    {
        $admin = $this->admin();

        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());

        $this->actingWithToken($admin)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertUnprocessable();

        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
    }

    public function test_last_administrator_cannot_be_demoted(): void
    {
        $admin = $this->admin();

        $this->actingWithToken($admin)
            ->putJson("/api/admin/users/{$admin->id}", ['role' => User::ROLE_USER])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The last administrator cannot be demoted.');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_admin_can_reset_a_users_password_and_revoke_tokens(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['password' => Hash::make('old-password')]);
        $oldToken = $this->tokenFor($user);

        $this->actingWithToken($admin)
            ->putJson("/api/admin/users/{$user->id}/password", [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('password');

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertFalse(Hash::check('old-password', $user->fresh()->password));
        $this->assertNotSame('new-password-123', $user->fresh()->password);
        $this->assertSame(0, $user->tokens()->count());

        // The previously issued token must no longer work.
        $this->forgetCachedGuards();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_password_reset_requires_confirmation_and_minimum_length(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingWithToken($admin)
            ->putJson("/api/admin/users/{$user->id}/password", [
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_regular_users_cannot_access_admin_endpoints(): void
    {
        $regular = User::factory()->create(['role' => User::ROLE_USER]);
        $other = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingWithToken($regular)
            ->getJson('/api/admin/users')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingWithToken($regular)
            ->getJson("/api/admin/users/{$other->id}")
            ->assertForbidden();

        $this->actingWithToken($regular)
            ->putJson("/api/admin/users/{$regular->id}", ['role' => User::ROLE_ADMIN])
            ->assertForbidden();

        $this->actingWithToken($regular)
            ->deleteJson("/api/admin/users/{$other->id}")
            ->assertForbidden();

        $this->actingWithToken($regular)
            ->putJson("/api/admin/users/{$other->id}/password", [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $regular->id,
            'role' => User::ROLE_USER,
        ]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_guests_cannot_access_admin_endpoints(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->getJson("/api/admin/users/{$user->id}")->assertUnauthorized();
        $this->putJson("/api/admin/users/{$user->id}", ['name' => 'Hacker'])->assertUnauthorized();
        $this->deleteJson("/api/admin/users/{$user->id}")->assertUnauthorized();
        $this->putJson("/api/admin/users/{$user->id}/password", [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnauthorized();
    }

    public function test_registration_cannot_assign_the_admin_role(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Sneaky User',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_ADMIN,
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', User::ROLE_USER)
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', [
            'email' => 'sneaky@example.com',
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_users_cannot_access_another_users_contacts(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $contact = Contact::create([
            'user_id' => $owner->id,
            'first_name' => 'Private',
            'last_name' => 'Contact',
        ]);

        $this->actingWithToken($intruder)
            ->getJson("/api/contacts/{$contact->id}")
            ->assertNotFound();

        $this->actingWithToken($intruder)
            ->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_passwords_remain_hashed_and_are_never_exposed(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);

        $this->assertNotSame('secret-password', $user->password);
        $this->assertTrue(Hash::check('secret-password', $user->password));

        $list = $this->actingWithToken($admin)
            ->getJson('/api/admin/users')
            ->assertOk();

        $list->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.tokens');

        $show = $this->actingWithToken($admin)
            ->getJson("/api/admin/users/{$user->id}")
            ->assertOk();

        $show->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $update = $this->actingWithToken($admin)
            ->putJson("/api/admin/users/{$user->id}", ['name' => 'Still Hashed'])
            ->assertOk();

        $update->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $this->assertTrue(Hash::check('secret-password', $user->fresh()->password));
    }
}
