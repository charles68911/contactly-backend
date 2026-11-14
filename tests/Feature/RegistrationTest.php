<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Account registered successfully.')
            ->assertJsonPath('user.name', 'Jane Doe')
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure([
                'success',
                'message',
                'user' => ['id', 'name', 'email', 'created_at', 'updated_at'],
                'token',
                'token_type',
            ])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertNotEmpty($response->json('token'));

        $user = User::where('email', 'jane@example.com')->firstOrFail();

        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_duplicate_email_cannot_register(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', $this->validPayload([
            'email' => 'taken@example.com',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload([
            'email' => 'not-an-email',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_confirmation_must_match(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload([
            'password_confirmation' => 'different-password',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_must_be_at_least_eight_characters(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_name_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['name']);

        $response = $this->postJson('/api/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_new_user_can_access_protected_routes_immediately(): void
    {
        $response = $this->postJson('/api/register', $this->validPayload());
        $token = $response->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'jane@example.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/contacts')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_contacts', 0);
    }

    public function test_new_user_can_login_after_registration(): void
    {
        $this->postJson('/api/register', $this->validPayload())->assertCreated();

        $response = $this->postJson('/api/login', [
            'email' => 'jane@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonMissingPath('user.password');

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_existing_user_can_still_login(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'existing@example.com',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'existing@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_new_user_only_sees_their_own_contacts(): void
    {
        $otherUser = User::factory()->create();
        Contact::create([
            'user_id' => $otherUser->id,
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $response = $this->postJson('/api/register', $this->validPayload());
        $token = $response->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
