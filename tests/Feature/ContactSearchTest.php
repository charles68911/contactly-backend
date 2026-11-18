<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithToken(User $user): static
    {
        return $this->withHeader(
            'Authorization',
            'Bearer '.$user->createToken('test-suite')->plainTextToken
        );
    }

    private function contact(User $user, array $attributes = []): Contact
    {
        return $user->contacts()->create(array_merge([
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'category' => 'Personal',
            'is_favorite' => false,
        ], $attributes));
    }

    public function test_search_is_case_insensitive_across_contact_fields(): void
    {
        $user = User::factory()->create();

        $this->contact($user, [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'MARIA@example.com',
            'phone' => '0917-555-1234',
        ]);

        $this->contact($user, [
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
            'email' => 'pedro@example.com',
            'phone' => '0928-111-2222',
        ]);

        // Lowercase query still matches a mixed-case first name.
        $this->actingWithToken($user)
            ->getJson('/api/contacts?search=maria')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Maria');

        // Mixed-case query matches a lowercase last name.
        $this->actingWithToken($user)
            ->getJson('/api/contacts?search=REYES')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Pedro');

        // Email and phone are searchable too.
        $this->actingWithToken($user)
            ->getJson('/api/contacts?search=maria@example')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingWithToken($user)
            ->getJson('/api/contacts?search=111-2222')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Pedro');
    }

    public function test_search_only_returns_the_authenticated_users_contacts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->contact($owner, ['first_name' => 'Shared', 'last_name' => 'Name']);
        $this->contact($other, ['first_name' => 'Shared', 'last_name' => 'Stranger']);

        $this->actingWithToken($owner)
            ->getJson('/api/contacts?search=shared')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Name');
    }

    public function test_search_without_matches_returns_an_empty_list(): void
    {
        $user = User::factory()->create();
        $this->contact($user, ['first_name' => 'Maria', 'last_name' => 'Santos']);

        $this->actingWithToken($user)
            ->getJson('/api/contacts?search=zzz-nobody')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('success', true);
    }
}
