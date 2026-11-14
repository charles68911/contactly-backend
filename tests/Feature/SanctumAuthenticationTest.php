<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctumAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Laravel guards cache the resolved user for the lifetime of a test.
     */
    private function forgetCachedGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_user_route_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-suite')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonMissingPath('password');
    }

    public function test_bearer_token_does_not_expire_and_survives_long_gaps(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-suite')->plainTextToken;

        // Tokens are persistent by default: no expires_at is written.
        $this->assertNull($user->tokens()->first()->expires_at);

        $this->travel(365)->days();
        $this->forgetCachedGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', $user->email);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current')->plainTextToken;
        $otherToken = $user->createToken('other')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->forgetCachedGuards();

        $this->withHeader('Authorization', 'Bearer '.$currentToken)
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->forgetCachedGuards();

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_invalid_and_revoked_tokens_are_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/user')
            ->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('test-suite')->plainTextToken;

        $user->tokens()->delete();
        $this->forgetCachedGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }
}
