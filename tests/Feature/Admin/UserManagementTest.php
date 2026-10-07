<?php

namespace Tests\Feature\Admin;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $admin;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'email' => 'new.person@example.test',
            'password' => 'secret-pass-1',
            'role' => 'user',
        ], $overrides);
    }

    // ---- Access ----

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_regular_users_cannot_reach_any_admin_user_page(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->get('/admin/users/create')->assertForbidden();
        $this->actingAs($user)->post('/admin/users', $this->payload())->assertForbidden();
        $this->actingAs($user)->get("/admin/users/{$other->id}/edit")->assertForbidden();
        $this->actingAs($user)->put("/admin/users/{$other->id}", $this->payload())->assertForbidden();
        $this->actingAs($user)->patch("/admin/users/{$other->id}/status", ['active' => false])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.test']);
        $this->assertTrue($other->fresh()->is_active);
    }

    // ---- List ----

    public function test_an_admin_sees_all_users(): void
    {
        $admin = $this->admin();
        User::factory()->count(2)->create();

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/users/Index')
                ->has('users', 3));
    }

    public function test_the_list_shows_open_and_total_task_counts(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Counted Person']);

        Task::factory()->create(['created_by' => $admin->id, 'assigned_to' => $user->id, 'status' => Task::STATUS_PENDING]);
        Task::factory()->create(['created_by' => $admin->id, 'assigned_to' => $user->id, 'status' => Task::STATUS_COMPLETED]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('users', 2)
                ->where('users', fn ($users) => collect($users)
                    ->contains(fn ($row) => $row['name'] === 'Counted Person'
                        && $row['total_tasks_count'] === 2
                        && $row['open_tasks_count'] === 1)));
    }

    // ---- Create ----

    public function test_an_admin_can_create_a_user(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/users', $this->payload())
            ->assertRedirect('/admin/users');

        $created = User::where('email', 'new.person@example.test')->firstOrFail();

        $this->assertSame(User::ROLE_USER, $created->role);
        $this->assertTrue($created->is_active);
        $this->assertNotNull($created->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-1', $created->password));
    }

    public function test_an_admin_can_create_another_admin(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/users', $this->payload(['role' => 'admin']))
            ->assertRedirect('/admin/users');

        $this->assertTrue(User::where('email', 'new.person@example.test')->firstOrFail()->isAdmin());
    }

    public function test_the_email_must_be_unique(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/users', $this->payload(['email' => $admin->email]))
            ->assertSessionHasErrors('email');
    }

    public function test_the_email_must_be_lowercase(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/users', $this->payload(['email' => 'Mixed.Case@Example.test']))
            ->assertSessionHasErrors('email');
    }

    public function test_the_password_must_be_at_least_eight_characters(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/users', $this->payload(['password' => 'short']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.test']);
    }

    public function test_an_invalid_role_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/users', $this->payload(['role' => 'superuser']))
            ->assertSessionHasErrors('role');
    }

    // ---- Edit ----

    public function test_an_admin_can_update_a_user_without_changing_the_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->put("/admin/users/{$user->id}", [
                'name' => 'Renamed',
                'email' => $user->email,
                'password' => '',
                'role' => 'user',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/users');

        $user->refresh();

        $this->assertSame('Renamed', $user->name);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_an_admin_can_set_a_new_password_for_a_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->put("/admin/users/{$user->id}", [
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'brand-new-pass',
                'role' => 'user',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/admin/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'password' => '',
                'role' => 'user',
            ])
            ->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    // ---- Deactivate and reactivate ----

    public function test_an_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->patch("/admin/users/{$user->id}/status", ['active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($admin)
            ->patch("/admin/users/{$user->id}/status", ['active' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch("/admin/users/{$admin->id}/status", ['active' => false])
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_a_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_active' => false])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_a_reactivated_user_can_log_in_again(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_active' => false])->save();
        $user->forceFill(['is_active' => true])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_logged_in_user_is_logged_out_after_being_deactivated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}