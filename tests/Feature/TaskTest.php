<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Write report',
            'description' => 'Quarterly numbers',
            'status' => 'todo',
            'priority' => 'medium',
            'due_date' => '2026-12-01',
        ], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_users_only_see_their_own_tasks(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Task::factory()->for($user)->create(['title' => 'My task']);
        Task::factory()->for($other)->create(['title' => 'Their task']);

        $this->actingAs($user)
            ->get('/tasks')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('tasks/Index')
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'My task'));
    }

    public function test_a_user_can_create_a_task(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/tasks', $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'title' => 'Write report',
        ]);
    }

    public function test_a_title_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/tasks', $this->validPayload(['title' => '']))
            ->assertSessionHasErrors('title');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/tasks', $this->validPayload(['status' => 'banana']))
            ->assertSessionHasErrors('status');
    }

    public function test_a_user_can_update_their_own_task(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['title' => 'Old title']);

        $this->actingAs($user)
            ->put("/tasks/{$task->id}", $this->validPayload(['title' => 'New title', 'status' => 'done']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'New title',
            'status' => 'done',
        ]);
    }

    public function test_a_user_cannot_update_another_users_task(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $task = Task::factory()->for($owner)->create(['title' => 'Original']);

        $this->actingAs($intruder)
            ->put("/tasks/{$task->id}", $this->validPayload(['title' => 'Hacked']))
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => 'Original']);
    }

    public function test_a_user_can_delete_their_own_task(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create();

        $this->actingAs($user)->delete("/tasks/{$task->id}");

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_a_user_cannot_delete_another_users_task(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $task = Task::factory()->for($owner)->create();

        $this->actingAs($intruder)
            ->delete("/tasks/{$task->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }
}