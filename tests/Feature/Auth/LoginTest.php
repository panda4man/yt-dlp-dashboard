<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_renders_login_page(): void
    {
        $this->get('/login')->assertStatus(200);
    }

    public function test_redirects_authenticated_user_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
    }

    public function test_authenticates_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email'    => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'secret'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_rejects_invalid_credentials(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    public function test_logs_out_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_redirects_unauthenticated_user_from_dashboard_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
