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

    // ---- Filters and search ----

    public function test_search_matches_the_title(): void
    {
        $user = User::factory()->create();

        Task::factory()->for($user)->create(['title' => 'Buy milk', 'description' => null]);
        Task::factory()->for($user)->create(['title' => 'Write report', 'description' => null]);

        $this->actingAs($user)
            ->get('/tasks?search=milk')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Buy milk')
                ->where('filters.search', 'milk'));
    }

    public function test_search_matches_the_description(): void
    {
        $user = User::factory()->create();

        Task::factory()->for($user)->create(['title' => 'Errand', 'description' => 'Pick up the parcel']);
        Task::factory()->for($user)->create(['title' => 'Other', 'description' => 'Nothing relevant']);

        $this->actingAs($user)
            ->get('/tasks?search=parcel')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Errand'));
    }

    public function test_tasks_can_be_filtered_by_status_and_priority(): void
    {
        $user = User::factory()->create();

        Task::factory()->for($user)->create(['title' => 'Match', 'status' => 'done', 'priority' => 'high']);
        Task::factory()->for($user)->create(['title' => 'Wrong status', 'status' => 'todo', 'priority' => 'high']);
        Task::factory()->for($user)->create(['title' => 'Wrong priority', 'status' => 'done', 'priority' => 'low']);

        $this->actingAs($user)
            ->get('/tasks?status=done&priority=high')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Match'));
    }

    public function test_overdue_filter_shows_only_past_due_unfinished_tasks(): void
    {
        $user = User::factory()->create();

        Task::factory()->for($user)->create([
            'title' => 'Overdue task',
            'status' => 'todo',
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        Task::factory()->for($user)->create([
            'title' => 'Finished late',
            'status' => 'done',
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        Task::factory()->for($user)->create([
            'title' => 'Due later',
            'status' => 'todo',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
        Task::factory()->for($user)->create([
            'title' => 'No due date',
            'status' => 'todo',
            'due_date' => null,
        ]);

        $this->actingAs($user)
            ->get('/tasks?overdue=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks.data', 1)
                ->where('tasks.data.0.title', 'Overdue task')
                ->where('filters.overdue', true));
    }

    public function test_filters_never_show_another_users_tasks(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Task::factory()->for($other)->create(['title' => 'Secret milk plan']);

        $this->actingAs($user)
            ->get('/tasks?search=milk')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tasks.data', 0));
    }

    public function test_an_invalid_status_filter_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/tasks?status=banana')
            ->assertSessionHasErrors('status');
    }
}