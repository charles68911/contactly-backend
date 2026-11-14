<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-suite')->plainTextToken;
    }

    private function actingWithToken(User $user): static
    {
        $this->forgetCachedGuards();

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

    private function requestResetToken(User $user): string
    {
        $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();

        $notification = Notification::sent($user, ResetPasswordNotification::class)->first();

        $this->assertNotNull($notification, 'Password reset notification was not sent.');

        return $notification->token;
    }

    public function test_authenticated_user_can_change_password_with_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $currentToken = $this->tokenFor($user);
        $otherToken = $this->tokenFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->putJson('/api/password/change', [
                'current_password' => 'old-password',
                'password' => 'New-password-123',
                'password_confirmation' => 'New-password-123',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password changed successfully.')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('password_hash')
            ->assertJsonMissingPath('token');

        $user->refresh();
        $this->assertTrue(Hash::check('New-password-123', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));

        // The hash must never appear in the response body.
        $this->assertStringNotContainsString($user->password, $response->getContent());

        // The current session remains authenticated after the change.
        $this->forgetCachedGuards();
        $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', $user->email);

        // Every other token is revoked.
        $this->forgetCachedGuards();
        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_password_change_rejects_an_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/password/change', [
                'current_password' => 'wrong-password',
                'password' => 'New-password-123',
                'password_confirmation' => 'New-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password')
            ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));

        // A rejected attempt must not invalidate the current session.
        $this->forgetCachedGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_password_change_requires_a_matching_confirmation(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingWithToken($user)
            ->putJson('/api/password/change', [
                'current_password' => 'old-password',
                'password' => 'New-password-123',
                'password_confirmation' => 'Different-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_password_change_enforces_a_minimum_length(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingWithToken($user)
            ->putJson('/api/password/change', [
                'current_password' => 'old-password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_password_change_validates_required_fields(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->actingWithToken($user)
            ->putJson('/api/password/change', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'current_password',
                'password',
                'password_confirmation',
            ]);

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_guests_cannot_change_passwords(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->putJson('/api/password/change', [
            'current_password' => 'old-password',
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])->assertUnauthorized();

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_user_cannot_change_another_users_password(): void
    {
        $owner = User::factory()->create([
            'password' => Hash::make('owner-password'),
        ]);
        $intruder = User::factory()->create([
            'password' => Hash::make('intruder-password'),
        ]);

        // Supplying another user's identifiers must be ignored; the endpoint
        // always targets the authenticated user.
        $this->actingWithToken($intruder)
            ->putJson('/api/password/change', [
                'user_id' => $owner->id,
                'email' => $owner->email,
                'current_password' => 'intruder-password',
                'password' => 'Intruder-password-123',
                'password_confirmation' => 'Intruder-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('owner-password', $owner->fresh()->password));
        $this->assertTrue(Hash::check('Intruder-password-123', $intruder->fresh()->password));
    }

    public function test_email_authorization_password_change_routes_are_removed(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/password/change/request')
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/password/change/confirm', ['token' => 'anything'])
            ->assertNotFound();
    }

    public function test_user_can_request_a_password_reset_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/api/password/forgot', ['email' => $user->email]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'message',
                'If an account exists with that email address, a password reset link has been sent.'
            );

        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $notification = Notification::sent($user, ResetPasswordNotification::class)->first();
        $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        $this->assertNotNull($record);
        $this->assertNotSame($notification->token, $record->token);
        $this->assertTrue(Hash::check($notification->token, $record->token));
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $known = $this->postJson('/api/password/forgot', ['email' => $user->email]);
        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    public function test_user_can_reset_password_with_a_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $oldToken = $this->tokenFor($user);

        $resetToken = $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('New-password-123', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->forgetCachedGuards();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'New-password-123',
        ])->assertOk();
    }

    public function test_password_reset_rejects_invalid_tokens(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_password_reset_token_expires(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $resetToken = $this->requestResetToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_password_reset_token_cannot_be_used_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
        ]);
        $resetToken = $this->requestResetToken($user);

        $payload = [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ];

        $this->postJson('/api/password/reset', $payload)->assertOk();

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'Other-password-123',
            'password_confirmation' => 'Other-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check('New-password-123', $user->fresh()->password));
    }

    public function test_password_reset_validates_password_strength(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $resetToken = $this->requestResetToken($user);

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'New-password-123',
            'password_confirmation' => 'Different-password-123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_password_reset_email_contains_a_frontend_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $notification = Notification::sent($user, ResetPasswordNotification::class)->first();
        $mail = $notification->toMail($user);

        $this->assertStringContainsString(
            config('contactly.frontend.password_reset_url'),
            $mail->actionUrl
        );
        $this->assertStringContainsString('token='.$token, $mail->actionUrl);
        $this->assertStringContainsString(
            'email='.rawurlencode($user->email),
            $mail->actionUrl
        );
    }

    public function test_forgot_password_endpoint_is_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/password/forgot', [
                'email' => "nobody{$i}@example.com",
            ])->assertOk();
        }

        $this->postJson('/api/password/forgot', [
            'email' => 'nobody5@example.com',
        ])->assertStatus(429);
    }
}
