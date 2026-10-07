<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway route, only for testing the middleware.
        Route::middleware(['web', 'auth', 'admin'])
            ->get('/_test/admin-only', fn () => 'admin area');
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $admin;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/_test/admin-only')->assertRedirect('/login');
    }

    public function test_regular_users_are_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/_test/admin-only')->assertForbidden();
    }

    public function test_admins_can_access_admin_routes(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/_test/admin-only')
            ->assertOk()
            ->assertSee('admin area');
    }
}