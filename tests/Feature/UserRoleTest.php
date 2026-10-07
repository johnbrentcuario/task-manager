<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_the_user_role(): void
    {
        $user = User::factory()->create();

        $this->assertSame(User::ROLE_USER, $user->fresh()->role);
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_admin_create_command_makes_a_verified_admin(): void
    {
        $this->artisan('admin:create', ['name' => 'Test Admin', 'email' => 'admin@example.test'])
            ->expectsQuestion('Password (minimum 8 characters)', 'long-enough-pass')
            ->expectsQuestion('Confirm password', 'long-enough-pass')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue($admin->isAdmin());
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_admin_create_command_rejects_mismatched_passwords(): void
    {
        $this->artisan('admin:create', ['name' => 'Test Admin', 'email' => 'admin@example.test'])
            ->expectsQuestion('Password (minimum 8 characters)', 'long-enough-pass')
            ->expectsQuestion('Confirm password', 'different-pass')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }
}