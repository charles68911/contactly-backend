<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function verify(User $user): string
    {
        $response = $this->postJson('/api/password/recovery/verify', [
            'email' => $user->email,
            'account_id' => $user->id,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        return $response->json('token');
    }

    public function test_account_verification_returns_a_hashed_single_use_token(): void
    {
        $user = User::factory()->create([
            'email' => 'recover@example.com',
        ]);

        $response = $this->postJson('/api/password/recovery/verify', [
            'email' => 'recover@example.com',
            'account_id' => $user->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'token', 'expires_at'])
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('user');

        $token = $response->json('token');

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));

        $record = DB::table('account_recovery_tokens')->first();

        $this->assertNotNull($record);
        $this->assertSame($user->id, (int) $record->user_id);
        $this->assertSame(hash('sha256', $token), $record->token_hash);
        $this->assertNotSame($token, $record->token_hash);
        $this->assertNull($record->used_at);

        $expiresAt = Carbon::parse($record->expires_at);

        $this->assertTrue(
            $expiresAt->between(now()->addMinutes(9), now()->addMinutes(11)),
            'Recovery token should expire after about 10 minutes.'
        );
    }

    public function test_account_verification_failure_is_generic_and_does_not_probe_accounts(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $wrongEmail = $this->postJson('/api/password/recovery/verify', [
            'email' => 'someone-else@example.com',
            'account_id' => $user->id,
        ]);

        $wrongId = $this->postJson('/api/password/recovery/verify', [
            'email' => $user->email,
            'account_id' => $user->id + 1000,
        ]);

        $missing = $this->postJson('/api/password/recovery/verify', [
            'email' => 'nobody@example.com',
            'account_id' => 999999,
        ]);

        foreach ([$wrongEmail, $wrongId, $missing] as $response) {
            $response->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('token');
        }

        // The message must not reveal which value was wrong.
        $this->assertSame($wrongEmail->json('message'), $wrongId->json('message'));
        $this->assertSame($wrongId->json('message'), $missing->json('message'));

        $this->assertDatabaseCount('account_recovery_tokens', 0);
    }

    public function test_account_verification_requires_both_email_and_account_id(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/password/recovery/verify', [
            'account_id' => $user->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/password/recovery/verify', [
            'email' => $user->email,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_id');

        $this->postJson('/api/password/recovery/verify', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'account_id']);

        $this->assertDatabaseCount('account_recovery_tokens', 0);
    }

    public function test_user_can_reset_password_with_a_valid_recovery_token(): void
    {
        $user = User::factory()->create([
            'email' => 'recover@example.com',
            'password' => Hash::make('old-password'),
        ]);
        $oldSession = $user->createToken('old-session')->plainTextToken;

        $token = $this->verify($user);

        $response = $this->postJson('/api/password/recovery/reset', [
            'token' => $token,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('password_hash')
            ->assertJsonMissingPath('token');

        $user->refresh();

        $this->assertTrue(Hash::check('New-password-123', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
        $this->assertStringNotContainsString($user->password, $response->getContent());

        $record = DB::table('account_recovery_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        $this->assertNotNull($record);
        $this->assertNotNull($record->used_at);

        // Recovery revokes previously issued API sessions.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$oldSession)
            ->getJson('/api/user')
            ->assertUnauthorized();

        // The new password works and the old one does not.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'New-password-123',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'old-password',
        ])->assertUnauthorized();
    }

    public function test_recovery_token_cannot_be_used_twice(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $token = $this->verify($user);

        $this->postJson('/api/password/recovery/reset', [
            'token' => $token,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])->assertOk();

        $this->postJson('/api/password/recovery/reset', [
            'token' => $token,
            'password' => 'Other-password-123',
            'password_confirmation' => 'Other-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('New-password-123', $user->fresh()->password));
    }

    public function test_expired_recovery_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $token = $this->verify($user);

        $this->travel(11)->minutes();

        $this->postJson('/api/password/recovery/reset', [
            'token' => $token,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_invalid_recovery_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/password/recovery/reset', [
            'token' => 'not-a-real-token',
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_new_verification_invalidates_the_previous_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $firstToken = $this->verify($user);
        $secondToken = $this->verify($user);

        $this->postJson('/api/password/recovery/reset', [
            'token' => $firstToken,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->postJson('/api/password/recovery/reset', [
            'token' => $secondToken,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('New-password-123', $user->fresh()->password));
        $this->assertDatabaseCount('account_recovery_tokens', 1);
    }

    public function test_reset_validates_password_confirmation_and_minimum_length(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $token = $this->verify($user);

        $this->postJson('/api/password/recovery/reset', [
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/password/recovery/reset', [
            'token' => $token,
            'password' => 'New-password-123',
            'password_confirmation' => 'Different-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/password/recovery/reset', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'password', 'password_confirmation']);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_recovery_endpoints_do_not_require_authentication(): void
    {
        $user = User::factory()->create();

        // Both calls succeed without an Authorization header.
        $token = $this->verify($user);

        $this->postJson('/api/password/recovery/reset', [
            'token' => $token,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])->assertOk();
    }
}
